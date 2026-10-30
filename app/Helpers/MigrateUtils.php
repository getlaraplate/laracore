<?php

declare(strict_types=1);

namespace Modules\Core\Helpers;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;
use Modules\Core\Locking\Locked;
use Modules\Core\Models\Concerns\HasValidity;

final class MigrateUtils
{
    /**
     * Add a portable B-tree index intended for exact and prefix matching.
     *
     * @param  string|list<string>  $columns
     */
    public static function prefixIndex(
        Blueprint $table,
        string|array $columns,
        ?string $name = null,
        ?ConnectionInterface $connection = null,
    ): void {
        $connection = self::connection($connection);
        $normalized_columns = self::normalizeColumns($columns);
        $index_name = $name ?? self::searchIndexName($table->getTable(), $normalized_columns, 'prefix', $connection);
        self::assertIdentifier($index_name);

        $table->index($normalized_columns, $index_name);
    }

    public static function fuzzyIndex(
        string $table,
        string $column,
        ?string $name = null,
        string $oracleSync = 'on_commit',
        ?ConnectionInterface $connection = null,
    ): void {
        $connection = self::connection($connection);
        self::assertIdentifier($table);
        self::assertIdentifier($column);
        self::assertOracleSync($oracleSync);
        $index_name = $name ?? self::searchIndexName($table, [$column], 'fuzzy', $connection);
        self::assertIdentifier($index_name);

        match ($connection->getDriverName()) {
            'pgsql' => self::createPostgresFuzzyIndex($table, $column, $index_name, $connection),
            'oracle' => self::createOracleContextIndex($table, $column, $index_name, $oracleSync, $connection),
            default => null,
        };
    }

    /**
     * @param  string|list<string>  $columns
     */
    public static function fullTextIndex(
        string $table,
        string|array $columns,
        string $language = 'simple',
        ?string $name = null,
        string $oracleSync = 'manual',
        ?ConnectionInterface $connection = null,
    ): void {
        $connection = self::connection($connection);
        self::assertIdentifier($table);
        self::assertIdentifier($language);
        self::assertOracleSync($oracleSync);
        $normalized_columns = self::normalizeColumns($columns);
        $index_name = $name ?? self::searchIndexName($table, $normalized_columns, 'fulltext', $connection);
        self::assertIdentifier($index_name);

        match ($connection->getDriverName()) {
            'pgsql' => self::createPostgresFullTextIndex($table, $normalized_columns, $language, $index_name, $connection),
            'mysql', 'mariadb' => self::createMysqlFullTextIndex($table, $normalized_columns, $index_name, $connection),
            'oracle' => count($normalized_columns) === 1
                ? self::createOracleContextIndex($table, $normalized_columns[0], $index_name, $oracleSync, $connection)
                : throw new InvalidArgumentException('Oracle CONTEXT indexes require one normalized search-text column.'),
            default => null,
        };
    }

    public static function dropFuzzyIndex(
        string $table,
        string $column,
        ?string $name = null,
        ?ConnectionInterface $connection = null,
    ): void {
        $connection = self::connection($connection);
        self::dropSpecializedSearchIndex(
            $table,
            $name ?? self::searchIndexName($table, [$column], 'fuzzy', $connection),
            $connection,
        );
    }

    /**
     * @param  string|list<string>  $columns
     */
    public static function dropFullTextIndex(
        string $table,
        string|array $columns,
        ?string $name = null,
        ?ConnectionInterface $connection = null,
    ): void {
        $connection = self::connection($connection);
        $normalized_columns = self::normalizeColumns($columns);
        self::dropSpecializedSearchIndex(
            $table,
            $name ?? self::searchIndexName($table, $normalized_columns, 'fulltext', $connection),
            $connection,
        );
    }

    /**
     * Add a unique index over the rows that are not soft-deleted.
     *
     * `unique([..., 'deleted_at'])` does not do this: `deleted_at` is null on every live row, and a null in a
     * unique key makes the row distinct on MySQL, MariaDB, PostgreSQL and SQLite. PostgreSQL and SQLite get a
     * partial index; Oracle a function-based index whose key is all null on a deleted row; MySQL and MariaDB a
     * stored `is_live` column (1, or null when deleted) added to the key. Call it after `Schema::create()`; the
     * table must have `deleted_at`.
     *
     * @param  list<string>  $columns
     */
    public static function liveUniqueIndex(
        string $table,
        array $columns,
        string $name,
        ?ConnectionInterface $connection = null,
    ): void {
        $connection = self::connection($connection);
        self::assertIdentifier($table);
        self::assertIdentifier($name);
        array_walk($columns, static fn (string $column) => self::assertIdentifier($column));

        $grammar = $connection->getQueryGrammar();
        $wrapped_columns = array_map(static fn (string $column): string => $grammar->wrap($column), $columns);

        match ($connection->getDriverName()) {
            'pgsql', 'sqlite' => $connection->statement(sprintf(
                'CREATE UNIQUE INDEX %s ON %s (%s) WHERE %s IS NULL',
                $grammar->wrap($name),
                $grammar->wrapTable($table),
                implode(', ', $wrapped_columns),
                $grammar->wrap('deleted_at'),
            )),
            'oracle' => $connection->statement(sprintf(
                'CREATE UNIQUE INDEX %s ON %s (%s)',
                $grammar->wrap($name),
                $grammar->wrapTable($table),
                implode(', ', array_map(
                    static fn (string $column): string => sprintf('CASE WHEN %s IS NULL THEN %s END', $grammar->wrap('deleted_at'), $column),
                    $wrapped_columns,
                )),
            )),
            default => $connection->getSchemaBuilder()->table($table, static function (Blueprint $blueprint) use ($connection, $table, $columns, $name): void {
                if (! $connection->getSchemaBuilder()->hasColumn($table, 'is_live')) {
                    $blueprint->tinyInteger('is_live')->nullable()->storedAs('IF(deleted_at IS NULL, 1, NULL)')->comment('1 on a live row, null on a deleted one, for unique keys over live rows');
                }

                $blueprint->unique([...$columns, 'is_live'], $name);
            }),
        };
    }

    /**
     * Add common timestamp columns to a table.
     *
     * @param  Blueprint  $table  The table blueprint
     * @param  bool  $hasCreateUpdate  Add created_at and updated_at columns
     * @param  bool  $hasSoftDelete  Add soft delete functionality
     * @param  bool  $hasLocks  Add locking columns
     * @param  bool  $hasValidity  Add validity period columns
     * @param  bool  $isValidityRequired  Make valid_from required
     * @param  string|null  $createdAtIndexName  Override the created_at index name
     * @param  bool  $hasUpdatedAt  Add updated_at with created_at; off for an append-only table
     *
     * @throws InvalidArgumentException When invalid parameter combination
     */
    public static function timestamps(
        Blueprint $table,
        bool $hasCreateUpdate = true,
        bool $hasSoftDelete = false,
        bool $hasLocks = false,
        bool $hasValidity = false,
        bool $isValidityRequired = true,
        ?string $createdAtIndexName = null,
        ?ConnectionInterface $connection = null,
        bool $hasUpdatedAt = true,
    ): void {
        $connection = self::connection($connection);
        $schema = $connection->getSchemaBuilder();
        $table_name = $table->getTable();

        if ($hasCreateUpdate) {
            if (! $schema->hasColumn($table_name, Model::CREATED_AT)) {
                $table->timestamp(Model::CREATED_AT)->nullable(false)->useCurrent();
            }

            if ($hasUpdatedAt && ! $schema->hasColumn($table_name, Model::UPDATED_AT)) {
                $table->timestamp(Model::UPDATED_AT)->nullable(false)->useCurrent()->useCurrentOnUpdate();
            }

            self::createDateIndex($table, Model::CREATED_AT, $createdAtIndexName, $connection);
        }

        if ($hasSoftDelete) {
            self::softDeletes($table, $connection);
        }

        if ($hasLocks) {
            self::locked($table, $connection);
        }

        if ($hasValidity) {
            $valid_from_column = HasValidity::validFromKey();
            $valid_to_column = HasValidity::validToKey();

            if (! $schema->hasColumn($table_name, $valid_from_column)) {
                if ($isValidityRequired) {
                    $table->datetime($valid_from_column)->nullable(false)->useCurrent();
                } else {
                    $table->datetime($valid_from_column)->nullable(true);
                }
            }

            if (! $schema->hasColumn($table_name, $valid_to_column)) {
                $table->datetime($valid_to_column)->nullable(true);
            }

            self::createDateIndex($table, $valid_from_column, null, $connection);

            self::createDateIndex($table, $valid_to_column, null, $connection);

            $index_name = $table_name . '_validity_idx';

            if (! $schema->hasIndex($table_name, [$valid_from_column, $valid_to_column]) && ! $schema->hasIndex($table_name, $index_name)) {
                $connection->afterCommit(function () use ($connection, $table, $table_name, $valid_from_column, $valid_to_column, $index_name): void {
                    $grammar = $connection->getQueryGrammar();
                    match ($connection->getDriverName()) {
                        'pgsql' => $connection->statement(sprintf(
                            'CREATE INDEX %s ON %s (%s DESC, %s)',
                            $grammar->wrap($index_name),
                            $grammar->wrapTable($table_name),
                            $grammar->wrap($valid_from_column),
                            $grammar->wrap($valid_to_column),
                        )),
                        default => $table->index([$valid_from_column, $valid_to_column], $index_name),
                    };
                });
            }
        }

        $index_name = $table_name . '_validity_deleted_idx';

        if ($hasSoftDelete && $hasValidity && ! $schema->hasIndex($table_name, [$valid_from_column, $valid_to_column, 'is_deleted']) && ! $schema->hasIndex($table_name, $index_name)) {
            $connection->afterCommit(function () use ($connection, $table, $table_name, $valid_from_column, $valid_to_column, $index_name): void {
                $grammar = $connection->getQueryGrammar();
                match ($connection->getDriverName()) {
                    'pgsql' => $connection->statement(sprintf(
                        'CREATE INDEX %s ON %s (%s DESC, %s, %s)',
                        $grammar->wrap($index_name),
                        $grammar->wrapTable($table_name),
                        $grammar->wrap($valid_from_column),
                        $grammar->wrap($valid_to_column),
                        $grammar->wrap('is_deleted'),
                    )),
                    default => $table->index([$valid_from_column, $valid_to_column, 'is_deleted'], $index_name),
                };
            });
        }
    }

    /**
     * Drop common timestamp columns from a table.
     *
     * @param  Blueprint  $table  The table blueprint
     * @param  bool  $hasCreateUpdate  Drop created_at and updated_at columns
     * @param  bool  $hasSoftDelete  Drop soft delete functionality
     * @param  bool  $hasLocks  Drop locking columns
     * @param  bool  $hasValidity  Drop validity period columns
     */
    public static function dropTimestamps(
        Blueprint $table,
        bool $hasCreateUpdate = true,
        bool $hasSoftDelete = false,
        bool $hasLocks = false,
        bool $hasValidity = false,
        ?ConnectionInterface $connection = null,
    ): void {
        $connection = self::connection($connection);
        $schema = $connection->getSchemaBuilder();
        $table_name = $table->getTable();

        if ($hasCreateUpdate) {
            $index_name = $table_name . '_created_at_idx';

            if ($schema->hasIndex($table_name, [Model::CREATED_AT]) || $schema->hasIndex($table_name, $index_name)) {
                $table->dropIndex($index_name);
            }

            if ($schema->hasColumn($table_name, Model::CREATED_AT)) {
                $table->dropColumn(Model::CREATED_AT);
            }

            if ($schema->hasColumn($table_name, Model::UPDATED_AT)) {
                $table->dropColumn(Model::UPDATED_AT);
            }
        }

        if ($hasSoftDelete) {
            self::dropSoftDeletes($table, $connection);
        }

        if ($hasLocks) {
            self::dropLocked($table, $connection);
        }

        if ($hasValidity) {
            $valid_from_column = HasValidity::validFromKey();
            $valid_to_column = HasValidity::validToKey();
            $index_name = $table_name . '.validity_range';

            if ($schema->hasIndex($table_name, [$valid_from_column, $valid_to_column]) || $schema->hasIndex($table_name, $index_name)) {
                $table->dropIndex($index_name);
            }

            if ($schema->hasColumn($table_name, $valid_from_column)) {
                $table->dropColumn($valid_from_column);
            }

            if ($schema->hasColumn($table_name, $valid_to_column)) {
                $table->dropColumn($valid_to_column);
            }
        }
    }

    private static function softDeletes(Blueprint $table, Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasColumn($table->getTable(), 'deleted_at')) {
            $table->softDeletes();
        }

        if (! $schema->hasColumn($table->getTable(), 'is_deleted')) {
            switch ($connection->getDriverName()) {
                case 'pgsql':
                case 'sqlite':
                    $table->boolean('is_deleted')->storedAs('deleted_at IS NOT NULL')->index($table->getTable() . '_is_deleted_idx')->comment('Whether the entity is deleted');

                    break;
                case 'oracle':
                    // Oracle richiede ancora i trigger
                    $table->boolean('is_deleted')->default(false)->index($table->getTable() . '_is_deleted_idx')->comment('Whether the entity is deleted');
                    $connection->afterCommit(function () use ($connection, $table): void {
                        self::createBooleanTriggers($table, 'deleted', $connection);
                    });

                    break;
                default:
                    // MySQL supporta generated columns
                    $table->boolean('is_deleted')->storedAs('IF(deleted_at IS NULL, 0, 1)')->index($table->getTable() . '_is_deleted_idx')->comment('Whether the entity is deleted');

                    break;
            }
        }
    }

    private static function dropSoftDeletes(Blueprint $table, Connection $connection): void
    {
        // Rimuoviamo i trigger solo per Oracle
        if ($connection->getDriverName() === 'oracle') {
            self::dropBooleanTriggers($table, 'deleted', $connection);
        }

        $schema = $connection->getSchemaBuilder();

        if ($schema->hasColumn($table->getTable(), 'deleted_at')) {
            $table->dropSoftDeletes();
        }

        if ($schema->hasColumn($table->getTable(), 'is_deleted')) {
            $table->dropColumn('is_deleted');
        }
    }

    /**
     * Adds the three lock columns.
     *
     * There is deliberately no `is_locked` column: a lock is void once
     * {@see Locked::lockedUntilColumn()} has passed, and no engine accepts a non-deterministic
     * expression such as `NOW()` in a generated column. Lock state is therefore computed, by
     * {@see \Modules\Core\Locking\Traits\HasLocks::isLocked()} and its matching query scope.
     */
    private static function locked(Blueprint $table, Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();
        $locked = new Locked();
        $locked_at_column = $locked->lockedAtColumn();

        if ($locked_at_column !== '' && $locked_at_column !== '0' && ! $schema->hasColumn($table->getTable(), $locked_at_column)) {
            $table->timestamp($locked_at_column)->nullable()->comment('The date and time when the entity was locked');
        }

        $locked_by_column = $locked->lockedByColumn();

        if ($locked_by_column !== '' && $locked_by_column !== '0' && ! $schema->hasColumn($table->getTable(), $locked_by_column)) {
            // Holds a users.id, which is bigint unsigned. No foreign key here: a lockable model may
            // live on a connection that does not carry the users table.
            $table->unsignedBigInteger($locked_by_column)->nullable()->comment('The user who locked the entity');
        }

        $locked_until_column = $locked->lockedUntilColumn();

        if ($locked_until_column !== '' && $locked_until_column !== '0' && ! $schema->hasColumn($table->getTable(), $locked_until_column)) {
            $table->timestamp($locked_until_column)->nullable()->index($table->getTable() . '_locked_until_idx')->comment('The moment the lock expires; null means it never does');
        }
    }

    private static function dropLocked(Blueprint $table, Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();

        // Rimuoviamo i trigger solo per Oracle
        if ($connection->getDriverName() === 'oracle') {
            self::dropBooleanTriggers($table, 'locked', $connection);
        }

        $locked = new Locked();
        $locked_until_column = $locked->lockedUntilColumn();

        if ($locked_until_column !== '' && $locked_until_column !== '0' && $schema->hasColumn($table->getTable(), $locked_until_column)) {
            $table->dropColumn($locked_until_column);
        }

        $locked_at_column = $locked->lockedAtColumn();

        if ($locked_at_column !== '' && $locked_at_column !== '0' && $schema->hasColumn($table->getTable(), $locked_at_column)) {
            $table->dropColumn($locked_at_column);
        }

        $locked_by_column = $locked->lockedByColumn();

        if ($locked_by_column !== '' && $locked_by_column !== '0' && $schema->hasColumn($table->getTable(), $locked_by_column)) {
            $table->dropColumn($locked_by_column);
        }

        if ($schema->hasColumn($table->getTable(), 'is_locked')) {
            $table->dropColumn('is_locked');
        }
    }

    private static function createBooleanTriggers(Blueprint $table, string $suffix, Connection $connection): void
    {
        if ($connection->getDriverName() === 'oracle') {
            // In Oracle we use a virtual column with a check constraint
            // Create trigger for Oracle
            $grammar = $connection->getQueryGrammar();
            $wrapped_trigger = $grammar->wrap($table->getTable() . '_is_' . $suffix . '_trigger');
            $wrapped_table = $grammar->wrapTable($table->getTable());
            $wrapped_virtual_column = $grammar->wrap('is_' . $suffix);
            $wrapped_source_column = $grammar->wrap($suffix . '_at');
            $connection->unprepared('
                CREATE OR REPLACE TRIGGER ' . $wrapped_trigger . '
                BEFORE INSERT OR UPDATE ON ' . $wrapped_table . '
                FOR EACH ROW
                BEGIN
                    :NEW.' . $wrapped_virtual_column . ' := CASE
                        WHEN :NEW.' . $wrapped_source_column . ' IS NOT NULL THEN 1
                        ELSE 0 
                    END;
                END;
            ');
        }
    }

    private static function dropBooleanTriggers(Blueprint $table, string $suffix, Connection $connection): void
    {
        if ($connection->getDriverName() === 'oracle') {
            $wrapped_trigger = $connection->getQueryGrammar()->wrap($table->getTable() . '_is_' . $suffix . '_trigger');
            $connection->unprepared('
                BEGIN
                    EXECUTE IMMEDIATE \'DROP TRIGGER ' . $wrapped_trigger . '\';
                EXCEPTION
                    WHEN OTHERS THEN
                        IF SQLCODE != -4080 THEN  -- ORA-04080: trigger does not exist
                            RAISE;
                        END IF;
                END;
            ');
        }
    }

    private static function createDateIndex(
        Blueprint $table,
        string $column,
        ?string $indexName,
        Connection $connection,
    ): void {
        if ($indexName !== null) {
            self::assertIdentifier($indexName);
        }

        $index_name = $indexName ?? $table->getTable() . '_' . $column . '_idx';
        $driver_name = $connection->getDriverName();

        $schema = $connection->getSchemaBuilder();

        if ($schema->hasIndex($table->getTable(), $column) || $schema->hasIndex($table->getTable(), $index_name)) {
            return;
        }

        if ($driver_name === 'pgsql') {
            $connection->afterCommit(function () use ($connection, $table, $column, $index_name): void {
                $grammar = $connection->getQueryGrammar();
                $connection->statement(sprintf(
                    'CREATE INDEX %s ON %s USING BRIN (%s)',
                    $grammar->wrap($index_name),
                    $grammar->wrapTable($table->getTable()),
                    $grammar->wrap($column),
                ));
            });

            return;
        }

        // MySQL and Oracle are not able to handle DESC indexes through Blueprint; to avoid
        // race conditions during table creation we use a standard index created along with the table.
        $table->index($column, $index_name);
    }

    /**
     * @param  string|list<string>  $columns
     * @return list<string>
     */
    private static function normalizeColumns(string|array $columns): array
    {
        $normalized = is_string($columns) ? [$columns] : array_values($columns);

        throw_if($normalized === [], InvalidArgumentException::class, 'At least one search index column is required.');

        foreach ($normalized as $column) {
            throw_unless(is_string($column), InvalidArgumentException::class, 'Search index columns must be strings.');
            self::assertIdentifier($column);
        }

        return $normalized;
    }

    private static function assertIdentifier(string $identifier): void
    {
        throw_unless(
            preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) === 1,
            InvalidArgumentException::class,
            sprintf('Invalid database identifier [%s].', $identifier),
        );
    }

    private static function assertOracleSync(string $sync): void
    {
        throw_unless(
            in_array($sync, ['manual', 'on_commit'], true),
            InvalidArgumentException::class,
            'Oracle Text sync must be [manual] or [on_commit].',
        );
    }

    /**
     * @param  list<string>  $columns
     */
    private static function searchIndexName(string $table, array $columns, string $suffix, Connection $connection): string
    {
        self::assertIdentifier($table);
        $base = mb_strtolower(implode('_', [$table, ...$columns, $suffix, 'idx']));
        $limit = match ($connection->getDriverName()) {
            'oracle' => 30,
            'pgsql' => 63,
            default => 64,
        };

        if ($limit >= mb_strlen($base)) {
            return $base;
        }

        $hash = mb_substr(sha1($base), 0, 8);

        return mb_substr($base, 0, $limit - 9) . '_' . $hash;
    }

    private static function createPostgresFuzzyIndex(
        string $table,
        string $column,
        string $indexName,
        Connection $connection,
    ): void {
        $grammar = $connection->getQueryGrammar();
        $connection->unprepared('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $connection->statement(sprintf(
            'CREATE INDEX %s ON %s USING GIN (%s gin_trgm_ops)',
            $grammar->wrap($indexName),
            $grammar->wrapTable($table),
            $grammar->wrap($column),
        ));
    }

    /**
     * @param  list<string>  $columns
     */
    private static function createPostgresFullTextIndex(
        string $table,
        array $columns,
        string $language,
        string $indexName,
        Connection $connection,
    ): void {
        $grammar = $connection->getQueryGrammar();
        $document = implode(" || ' ' || ", array_map(
            static fn (string $column): string => sprintf("coalesce(%s, '')", $grammar->wrap($column)),
            $columns,
        ));

        $connection->statement(sprintf(
            "CREATE INDEX %s ON %s USING GIN (to_tsvector('%s', %s))",
            $grammar->wrap($indexName),
            $grammar->wrapTable($table),
            $language,
            $document,
        ));
    }

    /**
     * @param  list<string>  $columns
     */
    private static function createMysqlFullTextIndex(
        string $table,
        array $columns,
        string $indexName,
        Connection $connection,
    ): void {
        $grammar = $connection->getQueryGrammar();
        $connection->statement(sprintf(
            'ALTER TABLE %s ADD FULLTEXT INDEX %s (%s)',
            $grammar->wrapTable($table),
            $grammar->wrap($indexName),
            implode(', ', array_map($grammar->wrap(...), $columns)),
        ));
    }

    private static function createOracleContextIndex(
        string $table,
        string $column,
        string $indexName,
        string $sync,
        Connection $connection,
    ): void {
        $parameters = $sync === 'on_commit' ? " PARAMETERS ('SYNC (ON COMMIT)')" : '';
        $grammar = $connection->getQueryGrammar();

        $connection->statement(sprintf(
            'CREATE INDEX %s ON %s (%s) INDEXTYPE IS CTXSYS.CONTEXT%s',
            $grammar->wrap($indexName),
            $grammar->wrapTable($table),
            $grammar->wrap($column),
            $parameters,
        ));
    }

    private static function dropSpecializedSearchIndex(
        string $table,
        string $indexName,
        Connection $connection,
    ): void {
        self::assertIdentifier($table);
        self::assertIdentifier($indexName);

        $grammar = $connection->getQueryGrammar();
        match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $connection->statement(sprintf('ALTER TABLE %s DROP INDEX %s', $grammar->wrapTable($table), $grammar->wrap($indexName))),
            'pgsql', 'oracle' => $connection->statement(sprintf('DROP INDEX %s', $grammar->wrap($indexName))),
            default => null,
        };
    }

    private static function connection(?ConnectionInterface $connection): Connection
    {
        $connection ??= app('db')->connection();

        throw_unless(
            $connection instanceof Connection,
            InvalidArgumentException::class,
            'Migration helpers require a Laravel database connection.',
        );

        return $connection;
    }
}
