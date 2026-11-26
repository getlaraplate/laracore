<?php

declare(strict_types=1);

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
