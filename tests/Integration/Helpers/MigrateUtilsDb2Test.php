<?php

declare(strict_types=1);

use Easi\DB2\Database\DB2Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;
use Modules\Core\Tests\Stubs\Database\Db2PretendConnection;

/**
 * @param  list<string>  $statements
 * @return list<string>
 */
function db2_statements_matching(array $statements, string $needle): array
{
    return array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, $needle)));
}

it('maintains is_deleted with a trigger on Db2', function (): void {
    $sql = Db2PretendConnection::capture(function (): void {
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            MigrateUtils::timestamps($table, hasSoftDelete: true);
        });
    });

    $joined = implode("\n", $sql);

    expect($joined)->toContain('is_deleted smallint constraint boolean_widgets_is_deleted check(is_deleted in(0, 1))  not null default 0')
        ->and($joined)->not->toContain('generated always');

    $triggers = db2_statements_matching($sql, 'TRIGGER');

    expect($triggers)->toHaveCount(1)
        ->and(preg_replace('/\s+/', ' ', mb_trim($triggers[0])))->toBe(
            'CREATE OR REPLACE TRIGGER widgets_is_deleted_trigger NO CASCADE BEFORE INSERT OR UPDATE ON widgets REFERENCING NEW AS N FOR EACH ROW SET N.is_deleted = CASE WHEN N.deleted_at IS NULL THEN 0 ELSE 1 END',
        )
        ->and(db2_statements_matching($sql, 'widgets_is_deleted_idx'))->not->toBeEmpty();
});

it('drops the is_deleted trigger with the column on Db2', function (): void {
    $sql = Db2PretendConnection::capture(function (): void {
        Schema::table('widgets', function (Blueprint $table): void {
            MigrateUtils::dropTimestamps($table, hasCreateUpdate: false, hasSoftDelete: true);
        });
    });

    expect($sql)->toContain('DROP TRIGGER widgets_is_deleted_trigger');
});

it('builds a live unique index on Db2 from is_live and a null-skipping index', function (): void {
    $sql = Db2PretendConnection::capture(function (): void {
        MigrateUtils::liveUniqueIndex('widgets', ['code', 'tenant_id'], 'widgets_live_unique', DB::connection());
    });

    $joined = implode("\n", $sql);

    expect($joined)->toContain('is_live smallint')
        ->and($joined)->not->toContain('generated always');

    $triggers = db2_statements_matching($sql, 'TRIGGER');

    expect($triggers)->toHaveCount(1)
        ->and(preg_replace('/\s+/', ' ', mb_trim($triggers[0])))->toBe(
            'CREATE OR REPLACE TRIGGER widgets_is_live_trigger NO CASCADE BEFORE INSERT OR UPDATE ON widgets REFERENCING NEW AS N FOR EACH ROW SET N.is_live = CASE WHEN N.deleted_at IS NULL THEN 1 END',
        )
        ->and($sql)->toContain('CREATE UNIQUE WHERE NOT NULL INDEX widgets_live_unique ON widgets (code, tenant_id, is_live)');
});

it('adds no full-text or fuzzy index on Db2', function (): void {
    $sql = Db2PretendConnection::capture(function (): void {
        MigrateUtils::fuzzyIndex('widgets', 'name', connection: DB::connection());
        MigrateUtils::fullTextIndex('widgets', ['name', 'body'], connection: DB::connection());
        MigrateUtils::dropFuzzyIndex('widgets', 'name', connection: DB::connection());
        MigrateUtils::dropFullTextIndex('widgets', ['name', 'body'], connection: DB::connection());
    });

    expect($sql)->toBe([]);
});

it('allows 128 characters in a Db2 index name', function (): void {
    $method = new ReflectionMethod(MigrateUtils::class, 'searchIndexName');
    $connection = Db2PretendConnection::make();
    $long = str_repeat('a', 100);

    $name = $method->invoke(null, $long, ['b'], 'fuzzy', $connection);
    $over = $method->invoke(null, str_repeat('a', 130), ['b'], 'fuzzy', $connection);

    expect($name)->toBe($long . '_b_fuzzy_idx')
        ->and(mb_strlen($name))->toBeGreaterThan(64)
        ->and(mb_strlen($over))->toBe(128);
});

it('defers afterCommit callbacks to the end of a captured migration, as a transaction does', function (): void {
    $deferred = Db2PretendConnection::captureMigration(static function (): void {
        DB::afterCommit(static fn (): bool => DB::statement('select 2 from sysibm.sysdummy1'));
        DB::statement('select 1 from sysibm.sysdummy1');
    });
    $immediate = Db2PretendConnection::capture(static function (): void {
        DB::afterCommit(static fn (): bool => DB::statement('select 2 from sysibm.sysdummy1'));
        DB::statement('select 1 from sysibm.sysdummy1');
    });

    expect($deferred)->toBe(['select 1 from sysibm.sysdummy1', 'select 2 from sysibm.sysdummy1'])
        ->and($immediate)->toBe(['select 2 from sysibm.sysdummy1', 'select 1 from sysibm.sysdummy1'])
        ->and(DB::connection()->transactionLevel())->toBeLessThan(2);
});

it('creates the trigger and the validity indexes after the table inside a migration transaction on Db2', function (): void {
    $sql = Db2PretendConnection::captureMigration(function (): void {
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            MigrateUtils::timestamps($table, hasSoftDelete: true, hasValidity: true);
        });
    });

    $positions = static fn (string $needle): array => array_keys(array_filter($sql, static fn (string $statement): bool => str_contains($statement, $needle)));

    expect($positions('create table widgets'))->toHaveCount(1)
        ->and($positions('widgets_is_deleted_trigger'))->toHaveCount(1)
        ->and($positions('widgets_is_deleted_trigger')[0])->toBeGreaterThan($positions('create table widgets')[0])
        ->and($sql)->toContain('CREATE INDEX widgets_validity_idx ON widgets (valid_from, valid_to)')
        ->and($sql)->toContain('CREATE INDEX widgets_validity_deleted_idx ON widgets (valid_from, valid_to, is_deleted)');
});

it('tolerates dropping a Db2 trigger that does not exist, however the driver reports it', function (?string $sql_state, string $message, bool $tolerated): void {
    $previous = new PDOException($message);
    $previous->errorInfo = [$sql_state, -204, $message];
    $pretend = Db2PretendConnection::make();
    $pretend->useDefaultSchemaGrammar();
    $connection = Mockery::mock(DB2Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('db2_ibmi_odbc');
    $connection->shouldReceive('getQueryGrammar')->andReturn($pretend->getQueryGrammar());
    $connection->shouldReceive('unprepared')->once()
        ->andThrow(new QueryException('db2', 'DROP TRIGGER widgets_is_deleted_trigger', [], $previous));

    $drop = static fn (): mixed => new ReflectionMethod(MigrateUtils::class, 'dropBooleanTriggers')
        ->invoke(null, new Blueprint($pretend, 'widgets'), 'deleted', $connection);

    if ($tolerated) {
        expect($drop())->toBeNull();

        return;
    }

    expect($drop)->toThrow(QueryException::class);
})->with([
    'undefined object' => ['42704', 'SQLSTATE[42704]: Undefined object', true],
    'ODBC table or view not found' => ['42S02', 'SQLSTATE[42S02]: Base table or view not found', true],
    'SQL0204 in the message only' => ['HY000', '[IBM][System i Access ODBC Driver][DB2 for i5/OS]SQL0204 - WIDGETS_IS_DELETED_TRIGGER in LARAPLATE type *N not found.', true],
    'any other error' => ['42501', 'SQLSTATE[42501]: SQL0551 - Not authorized', false],
]);
