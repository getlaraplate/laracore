<?php

declare(strict_types=1);

namespace Modules\Core\Inspector;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Cache\CacheManager;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\CacheLookup;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Inspector\Entities\Column;
use Modules\Core\Inspector\Entities\ForeignKey;
use Modules\Core\Inspector\Entities\Index;
use Modules\Core\Inspector\Entities\Table;

final class Inspect
{
    public static function keyName(string $name, ?string $schema = null): string
    {
        return ($schema ?? 'default') . '_' . $name;
    }

    /**
     * Retrieve a particular table for a given database connection.
     */
    public static function table(string $name, ?string $schema = null): ?Table
    {
        $index = self::labelIndex();
        $lookup = self::lookup($index, $name, $schema);

        if ($lookup->hit && $lookup->value instanceof Table) {
            return $lookup->value;
        }

        $connection = Schema::connection($schema);
        $tables = $connection->getTables();
        $table = Arr::first($tables, fn (array $table): bool => $table['name'] === $name);

        if (! is_array($table)) {
            return null;
        }

        $database_name = $connection->getConnection()->getDatabaseName();
        $columns = self::parseColumns($connection->getColumns($table['name']));
        $indexes = self::parseIndexes($connection->getIndexes($table['name']));
        $foreignKeys = self::parseForeignKeys($connection->getForeignKeys($table['name']), $database_name, $schema);

        $inspected_data = new Table(
            $table['name'],
            $columns,
            $indexes,
            $foreignKeys,
            $database_name,
            $schema,
        );

        $index->store($lookup, $inspected_data, (int) config('cache.duration.long'));

        return $inspected_data;
    }

    public static function forget(string $name, ?string $schema = null): void
    {
        $index = self::labelIndex();
        $index->forget(self::lookup($index, $name, $schema));
    }

    /**
     * Retrieve the columns of a particular table for a given database connection.
     *
     * @return Collection<int, Column>
     */
    public static function columns(string $table, ?string $schema = null): Collection
    {
        $table = self::table($table, $schema);

        if ($table instanceof Table) {
            return $table->columns;
        }

        return new Collection();
    }

    /**
     * Retrieve the indexes of a particular table for a given database connection.
     *
     * @return Collection<int, Index>
     */
    public static function indexes(string $table, ?string $schema = null): Collection
    {
        $table = self::table($table, $schema);

        if ($table instanceof Table) {
            return $table->indexes;
        }

        return new Collection();
    }

    /**
     * Retrieve the foreign keys of a particular table for a given database connection.
     *
     * @return Collection<int, ForeignKey>
     */
    public static function foreignKeys(string $table, ?string $schema = null): Collection
    {
        $table = self::table($table, $schema);

        if ($table instanceof Table) {
            return $table->foreignKeys;
        }

        return new Collection();
    }

    /**
     * Retrieve a particular column of a particular table for a given
     * database connection.
     */
    public static function column(string $name, string $table, ?string $schema = null): ?Column
    {
        $columns = self::columns($table, $schema);

        return $columns->first(fn (Column $column): bool => $column->name === $name);
    }

    /**
     * Retrieve a particular index of a particular table for a given
     * database connection.
     */
    public static function index(string $name, string $table, ?string $schema = null): ?Index
    {
        $indexes = self::indexes($table, $schema);

        return $indexes->first(fn (Index $index): bool => $index->name === $name);
    }

    /**
     * Retrieve a particular foreign key of a particular table for a given
     * database connection.
     */
    public static function foreignKey(string $name, string $table, ?string $schema = null): ?ForeignKey
    {
        $foreigns = self::foreignKeys($table, $schema);

        return $foreigns->first(fn (ForeignKey $foreign): bool => $foreign->name === $name);
    }

    private static function labelIndex(): ICacheLabelIndex
    {
        return resolve(ICacheLabelIndex::class);
    }

    /**
     * The lookup of an inspected table. It carries the `inspector` label, flushed when a migration ends.
     */
    private static function lookup(ICacheLabelIndex $index, string $name, ?string $schema): CacheLookup
    {
        return $index->lookup(
            CacheManager::key('inspector', self::keyName($name, $schema)),
            new CacheDependencies([CacheLabel::inspector()]),
        );
    }

    /**
     * @param  array<string, mixed>  $column
     * @return Collection<int, string>
     */
    private static function getAttributesForColumn(array $column): Collection
    {
        $attributes = [];

        $type_name = self::optionalString($column, 'type_name');

        if ($type_name !== null) {
            $attributes[] = $type_name;
        }

        if (($column['auto_increment'] ?? false) === true) {
            $attributes[] = 'autoincrement';
        }

        if (($column['nullable'] ?? false) === true) {
            $attributes[] = 'nullable';
        }

        $collation = self::optionalString($column, 'collation');

        if ($collation !== null) {
            $attributes[] = $collation;
        }

        return collect($attributes);
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     * @return Collection<int, Column>
     */
    private static function parseColumns(array $columns): Collection
    {
        return collect($columns)->map(static fn (array $column): Column => new Column(
            self::requiredString($column, 'name'),
            self::getAttributesForColumn($column),
            $column['default'] ?? null,
            self::requiredString($column, 'type'),
        ));
    }

    /**
     * @param  array<string, mixed>  $index
     * @return Collection<int, string>
     */
    private static function getAttributesForIndex(array $index): Collection
    {
        $attributes = [];
        $type = self::optionalString($index, 'type');

        if ($type !== null) {
            $attributes[] = $type;
        }

        $index_columns = self::stringList($index['columns'] ?? []);

        if (count($index_columns) > 1) {
            $attributes[] = 'compound';
        }

        if (($index['unique'] ?? false) === true && ($index['primary'] ?? false) !== true) {
            $attributes[] = 'unique';
        }

        if (($index['primary'] ?? false) === true) {
            $attributes[] = 'primary';
        }

        return collect($attributes);
    }

    /**
     * @param  array<int, array<string, mixed>>  $indexes
     * @return Collection<int, Index>
     */
    private static function parseIndexes(array $indexes): Collection
    {
        return collect($indexes)->map(static function (array $index): Index {
            return new Index(
                self::requiredString($index, 'name'),
                collect(self::stringList($index['columns'] ?? [])),
                self::getAttributesForIndex($index),
            );
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $keys
     * @return Collection<int, ForeignKey>
     */
    private static function parseForeignKeys(array $keys, string $schema, ?string $connection = null): Collection
    {
        return collect($keys)->map(function (array $foreignKey) use ($schema, $connection): ForeignKey {
            $columns = self::stringList($foreignKey['columns'] ?? []);
            $foreign_columns = self::stringList($foreignKey['foreign_columns'] ?? []);
            $foreign_table = self::requiredString($foreignKey, 'foreign_table');
            $name = self::optionalString($foreignKey, 'name') ?? ($foreign_table . '_' . implode('_', $columns));

            return new ForeignKey(
                $name,
                collect($columns),
                self::optionalString($foreignKey, 'foreign_schema'),
                $foreign_table,
                collect($foreign_columns),
                $schema,
                $connection,
                self::optionalString($foreignKey, 'on_update'),
                self::optionalString($foreignKey, 'on_delete'),
            );
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<int, string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }
}
