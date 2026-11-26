<?php

declare(strict_types=1);

namespace Modules\Core\Search\Support;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Search\Contracts\IProfileVectorIndex;
use Override;

/**
 * One partial HNSW index per embedding profile on the pgvector `embedding` column.
 *
 * The column has no dimension, so it can hold vectors of different lengths; an index needs one, so
 * it is an expression index on `embedding::vector(N)` restricted to the rows of one `model_key`.
 * Queries must use the same cast and the same filter to be served by it
 * ({@see \Modules\Core\Search\Engines\DatabaseEngine}).
 */
final class PgvectorProfileIndex implements IProfileVectorIndex
{
    private const string NAME_PREFIX = 'me_embedding_';

    private const array OPERATOR_CLASSES = [
        'cosine' => 'vector_cosine_ops',
        'l2' => 'vector_l2_ops',
        'ip' => 'vector_ip_ops',
    ];

    private const array DISTANCE_OPERATORS = [
        'cosine' => '<=>',
        'l2' => '<->',
        'ip' => '<#>',
    ];

    /**
     * @var array<string, true>
     */
    private array $ensured = [];

    /**
     * The index name: a fixed prefix and a hash of the key, so it is a valid identifier (at most
     * 63 bytes) whatever characters the key holds.
     */
    public static function indexName(string $modelKey): string
    {
        return self::NAME_PREFIX . mb_substr(sha1($modelKey), 0, 12);
    }

    /**
     * The distance operator that matches the index operator class of a similarity.
     *
     * @throws InvalidArgumentException
     */
    public static function distanceOperator(string $similarity): string
    {
        return self::DISTANCE_OPERATORS[$similarity] ?? throw self::unknownSimilarity($similarity);
    }

    /**
     * @throws InvalidArgumentException
     *
     * @return list<string>
     */
    public static function statements(string $table, string $modelKey, int $dimensions, string $similarity): array
    {
        $operator_class = self::OPERATOR_CLASSES[$similarity] ?? throw self::unknownSimilarity($similarity);

        if ($dimensions < 1) {
            throw new InvalidArgumentException("The vector dimensions must be positive, {$dimensions} given.");
        }

        $name = self::quoteIdentifier(self::indexName($modelKey));
        $table = self::quoteIdentifier($table);
        $key = self::quoteLiteral($modelKey);

        return [
            "CREATE INDEX IF NOT EXISTS {$name} ON {$table} USING hnsw ((\"embedding\"::vector({$dimensions})) {$operator_class}) WHERE (\"model_key\" = {$key})",
        ];
    }

    public static function dropStatement(string $modelKey): string
    {
        return 'DROP INDEX IF EXISTS ' . self::quoteIdentifier(self::indexName($modelKey));
    }

    /**
     * Quotes a string as a SQL literal, the way the index predicate and the search query both write it.
     */
    public static function quoteLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Only PostgreSQL with the `vector` extension; every other driver, Oracle and Db2 included, has no profile index.
     */
    #[Override]
    public function supports(Connection $connection): bool
    {
        return $connection->getDriverName() === 'pgsql'
            && $connection->table('pg_extension')->where('extname', 'vector')->exists();
    }

    #[Override]
    public function ensure(Connection $connection, string $modelKey, int $dimensions, string $similarity): void
    {
        // A catalog read first: CREATE INDEX IF NOT EXISTS takes its ShareLock on the table before
        // it looks at the name, which would queue behind (and block) concurrent writers.
        // The build itself is deliberately not CONCURRENTLY: it is a maintenance operation and
        // vector search is suspended while a switch runs.
        if ($this->exists($connection, $modelKey)) {
            $this->ensured[$modelKey] = true;

            return;
        }

        $table = $connection->getTablePrefix() . CoreTables::ModelEmbeddings->value;

        foreach (self::statements($table, $modelKey, $dimensions, $similarity) as $statement) {
            $connection->statement($statement);
        }

        $this->ensured[$modelKey] = true;
    }

    #[Override]
    public function ensureOnce(Connection $connection, string $modelKey, int $dimensions, string $similarity): void
    {
        if (isset($this->ensured[$modelKey])) {
            return;
        }

        $this->ensure($connection, $modelKey, $dimensions, $similarity);
    }

    #[Override]
    public function drop(Connection $connection, string $modelKey): void
    {
        $connection->statement(self::dropStatement($modelKey));

        unset($this->ensured[$modelKey]);
    }

    private static function unknownSimilarity(string $similarity): InvalidArgumentException
    {
        return new InvalidArgumentException("Unknown vector similarity \"{$similarity}\"; expected cosine, l2 or ip.");
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function exists(Connection $connection, string $modelKey): bool
    {
        return $connection->selectOne(
            'SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?',
            [self::indexName($modelKey)],
        ) !== null;
    }
}
