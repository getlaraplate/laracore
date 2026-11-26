<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;

/*
| The Db2 test group. Run on Db2 with `composer test:db2` (phpunit.db2.xml); in the default suite these
| tests run on SQLite like any other. See the "Db2 test group" section of the Core README.
*/

it('rolls a transaction back for real', function (): void {
    $name = 'db2.rollback.' . uniqid();

    try {
        DB::transaction(function () use ($name): void {
            Setting::query()->forceCreate(['name' => $name, 'value' => 'inside', 'description' => '']);

            throw new RuntimeException('force the rollback');
        });
    } catch (RuntimeException) {
        // Expected: the rollback is what the test looks at.
    }

    expect(Setting::query()->where('name', $name)->exists())->toBeFalse();
})->group('db2');

it('reads the schema back through introspection', function (): void {
    // The users table is `users`: the application's table, not a prefixed core_ one.
    $columns = array_map(
        static fn (array $column): string => mb_strtolower((string) $column['name']),
        Schema::getColumns(new User()->getTable()),
    );

    expect($columns)->toContain('id', 'name', 'email', 'deleted_at', 'is_deleted');
})->group('db2');

it('quotes and finds a reserved-word column', function (): void {
    $name = 'db2.reserved.' . uniqid();

    // core_settings.value is a reserved word on Db2 and has to be quoted by the grammar.
    $created = Setting::query()->forceCreate(['name' => $name, 'value' => 'stored', 'description' => '']);
    $reloaded = Setting::query()->whereKey($created->getKey())->sole();

    expect($reloaded->name)->toBe($name)
        ->and($reloaded->value)->toBe('stored');
})->group('db2');
