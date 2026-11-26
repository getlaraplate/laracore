<?php

declare(strict_types=1);

use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Builder as ScoutBuilder;
use Modules\Core\Search\Engines\DatabaseEngine;
use Modules\Core\Support\Db2Drivers;
use Modules\Core\Tests\Stubs\Database\Db2PretendConnection;
use Modules\Core\Tests\Stubs\Search\DatabaseEngineSQLiteVectorSearchUser;

it('publishes the db2 text match capabilities like the oracle ones, without an adapter', function (): void {
    $capabilities = (new DatabaseEngine)->textMatchCapabilities();

    expect($capabilities)->toHaveKey(Db2Drivers::FAMILY)
        ->and($capabilities[Db2Drivers::FAMILY])->toBe([
            'typo_tolerance' => false,
            'prefix' => true,
            'degraded' => ['typo_tolerance', 'exact_match_boost', 'operator'],
        ]);
});

it('reads the driver of the connection, not the name of the default connection', function (): void {
    $previous = DB::getDefaultConnection();
    $pdo = DB::connection()->getPdo();
    config()->set('database.connections.main', ['driver' => 'pgsql', 'database' => ':memory:', 'prefix' => '']);
    DB::extend('main', static fn (array $config): SQLiteConnection => new SQLiteConnection($pdo, ':memory:', '', $config));
    DB::purge('main');
    DB::setDefaultConnection('main');

    $method = new ReflectionMethod(DatabaseEngine::class, 'getDatabaseDriver');
    $method->setAccessible(true);

    try {
        expect($method->invoke(new DatabaseEngine))->toBe('pgsql');
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('main');
    }

    expect($method->invoke(new DatabaseEngine, new DatabaseEngineSQLiteVectorSearchUser))->toBe(DB::connection()->getDriverName());
});

it('compiles the portable keyword match on a Db2 model and skips the vector arm', function (string $driver): void {
    $sql = Db2PretendConnection::capture(static function (): void {
        $model = (new DatabaseEngineSQLiteVectorSearchUser)->setConnection('db2_pretend');
        $builder = new ScoutBuilder($model, 'mario');

        expect((new ReflectionMethod(DatabaseEngine::class, 'runsVectorSearch'))->invoke(new DatabaseEngine, $builder))->toBeFalse();

        $builder->query = 'mario';
        $builder->constrain = null;
        (new DatabaseEngine)->search($builder);
    }, $driver);

    expect(implode("\n", $sql))->toContain('LOWER(')->toContain('LIKE LOWER(?)');
})->with(Db2Drivers::DRIVERS);

it('keeps the vector path on a driver that has one', function (): void {
    $builder = new ScoutBuilder(new DatabaseEngineSQLiteVectorSearchUser, 'vector:[1,0,0]');

    expect((new ReflectionMethod(DatabaseEngine::class, 'runsVectorSearch'))->invoke(new DatabaseEngine, $builder))
        ->toBe((new DatabaseEngine)->isVectorSearch($builder));
});

it('forces case-insensitive column search on the default Db2 connection only', function (): void {
    $column = static fn (): TextColumn => TextColumn::make('name')->searchable();

    expect($column()->isSearchForcedCaseInsensitive())->toBeNull();

    Db2PretendConnection::capture(static function () use ($column): void {
        expect($column()->isSearchForcedCaseInsensitive())->toBeTrue();
    });

    expect($column()->isSearchForcedCaseInsensitive())->toBeNull();
});

it('drops the vector wheres of a semantic search on Db2 and keeps the keyword match', function (string $field, bool $paginate): void {
    $builder = null;

    $sql = Db2PretendConnection::capture(static function () use ($field, $paginate, &$builder): void {
        $model = (new DatabaseEngineSQLiteVectorSearchUser)->setConnection('db2_pretend');
        $builder = new ScoutBuilder($model, 'mario');
        $builder->where($field, [1.0, 0.0, 0.0]);
        $builder->where('username', 'mario');

        if ($paginate) {
            (new DatabaseEngine)->paginateUsingDatabase($builder, 15, 'page', 1);

            return;
        }

        (new DatabaseEngine)->search($builder);
    });

    $all = implode("\n", $sql);

    expect($all)->not->toContain('embedding')->not->toContain('"vector"')
        ->and($all)->toContain('LIKE LOWER(?)')->toContain('and username = ?')
        ->and($builder->wheres)->toHaveCount(2);
})->with([
    'embedding search' => ['embedding', false],
    'vector search' => ['vector', false],
    'embedding paginate' => ['embedding', true],
    'vector paginate' => ['vector', true],
]);
