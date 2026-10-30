<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Engines\DatabaseEngine;
use Modules\Core\Search\Support\PgvectorProfileIndex;
use Modules\Core\Tests\Support\FakeVectorProfile;

/*
 * Runs only against a scratch PostgreSQL with pgvector named by PGVECTOR_TEST_HOST / _PORT /
 * _DATABASE / _USERNAME / _PASSWORD (never the application's connection). Everything happens in a
 * schema created and dropped by the test.
 */
const PGV_SCHEMA = 'pgvector_profile_index_test';

function pgvector_test_connection(): ?Connection
{
    $database = getenv('PGVECTOR_TEST_DATABASE');

    if (! is_string($database) || $database === '') {
        return null;
    }

    config()->set('database.connections.pgvector_test', [
        'driver' => 'pgsql',
        'host' => getenv('PGVECTOR_TEST_HOST') ?: '127.0.0.1',
        'port' => getenv('PGVECTOR_TEST_PORT') ?: '5432',
        'database' => $database,
        'username' => getenv('PGVECTOR_TEST_USERNAME') ?: 'postgres',
        'password' => getenv('PGVECTOR_TEST_PASSWORD') ?: '',
        'charset' => 'utf8',
        'prefix' => '',
        'schema' => 'public',
    ]);

    try {
        $connection = DB::connection('pgvector_test');
        $available = $connection->table('pg_available_extensions')->where('name', 'vector')->exists();
    } catch (Throwable) {
        return null;
    }

    return $available ? $connection : null;
}

beforeEach(function (): void {
    $connection = pgvector_test_connection();

    if (! $connection instanceof Connection) {
        $this->markTestSkipped('No PostgreSQL with pgvector configured (PGVECTOR_TEST_DATABASE).');
    }

    $connection->statement('CREATE EXTENSION IF NOT EXISTS vector');
    $connection->statement('DROP SCHEMA IF EXISTS ' . PGV_SCHEMA . ' CASCADE');
    $connection->statement('CREATE SCHEMA ' . PGV_SCHEMA);
    $connection->statement('SET search_path TO ' . PGV_SCHEMA . ', public');
    $connection->statement('CREATE TABLE core_model_embeddings (id bigserial primary key, model_type varchar, model_id bigint, embedding vector not null, locale varchar, model_key varchar)');

    $this->connection = $connection;
});

afterEach(function (): void {
    if (isset($this->connection)) {
        $this->connection->statement('RESET enable_seqscan');
        $this->connection->statement('RESET search_path');
        $this->connection->statement('DROP SCHEMA IF EXISTS ' . PGV_SCHEMA . ' CASCADE');
    }
});

function pgvector_insert(Connection $connection, string $key, string $vector, int $id): void
{
    $connection->statement('INSERT INTO core_model_embeddings (model_type, model_id, embedding, model_key) VALUES (?, ?, ?::vector, ?)', [ModelEmbedding::class, $id, $vector, $key]);
}

it('stores vectors of two lengths in the dimensionless column', function (): void {
    pgvector_insert($this->connection, 'a', '[1,2,3]', 1);
    pgvector_insert($this->connection, 'b', '[1,2,3,4]', 2);

    expect($this->connection->table('core_model_embeddings')->count())->toBe(2);
});

it('creates and drops the partial index of a profile, idempotently', function (): void {
    $index = new PgvectorProfileIndex;
    $name = PgvectorProfileIndex::indexName('a');
    $exists = fn (): bool => $this->connection->table('pg_indexes')->where('schemaname', PGV_SCHEMA)->where('indexname', $name)->exists();

    expect($index->supports($this->connection))->toBeTrue();

    $index->ensure($this->connection, 'a', 3, 'cosine');
    $index->ensure($this->connection, 'a', 3, 'cosine');
    expect($exists())->toBeTrue();

    $index->drop($this->connection, 'a');
    $index->drop($this->connection, 'a');
    expect($exists())->toBeFalse();
});

it('returns only the active model rows and can be served by the index', function (): void {
    pgvector_insert($this->connection, 'a', '[1,2,3]', 1);
    pgvector_insert($this->connection, 'a', '[3,2,1]', 2);
    pgvector_insert($this->connection, 'b', '[1,2,3,4]', 3);

    (new PgvectorProfileIndex)->ensure($this->connection, 'a', 3, 'cosine');

    FakeVectorProfile::use(new VectorProfile('a', 3, 'cosine'));

    $method = new ReflectionMethod(DatabaseEngine::class, 'postgreSQLVectorSearchQuery');
    $query = $method->invoke(app(DatabaseEngine::class), [1.0, 2.0, 3.0], new ModelEmbedding, null);
    $query->limit(2);

    $rows = $this->connection->select($query->toSql(), $query->getBindings());

    expect(array_map(fn (object $row): int => (int) $row->model_id, $rows))->toBe([1, 2]);

    $this->connection->statement('SET enable_seqscan = off');
    $plan = collect($this->connection->select('EXPLAIN ' . $query->toSql(), $query->getBindings()))
        ->map(fn (object $row): string => implode(' ', (array) $row))->implode("\n");

    expect($plan)->toContain(PgvectorProfileIndex::indexName('a'));
});
