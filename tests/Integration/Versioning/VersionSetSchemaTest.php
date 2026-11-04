<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionEpoch;
use Modules\Core\Models\VersionSet;

it('creates version sets before versions with the expected columns', function (): void {
    $version_sets_table = CoreTables::VersionSets->value;
    $versions_table = CoreTables::Versions->value;
    $user_foreign_key = config('versionable.user_foreign_key', 'user_id');

    expect(Schema::hasTable($version_sets_table))->toBeTrue()
        ->and(Schema::hasTable($versions_table))->toBeTrue()
        ->and(Schema::hasColumns($version_sets_table, [
            'id',
            'uuid',
            'root_type',
            'root_id',
            'root_connection_ref',
            'root_table_ref',
            $user_foreign_key,
            'kind',
            'reason',
            'reverted_from_set_id',
            'created_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn($version_sets_table, 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumns($versions_table, [
            'version_set_id',
            'sequence',
            'change_type',
            'relation_path',
            'subject_key',
        ]))->toBeTrue();
});

it('uses an auto-increment local key and nullable pre-persist root fields', function (): void {
    $columns = collect(Schema::getColumns(CoreTables::VersionSets->value))
        ->keyBy(static fn (array $column): string => mb_strtolower($column['name']));
    $user_foreign_key = config('versionable.user_foreign_key', 'user_id');

    expect($columns['uuid']['nullable'])->toBeFalse()
        ->and($columns['root_type']['nullable'])->toBeTrue()
        ->and($columns['root_id']['nullable'])->toBeTrue()
        ->and($columns['root_connection_ref']['nullable'])->toBeTrue()
        ->and($columns['root_table_ref']['nullable'])->toBeTrue()
        ->and($columns[$user_foreign_key]['nullable'])->toBeTrue()
        ->and($columns['kind']['nullable'])->toBeFalse()
        ->and($columns['reason']['nullable'])->toBeTrue()
        ->and($columns['reverted_from_set_id']['nullable'])->toBeTrue();

    if (Schema::getConnection()->getDriverName() !== 'oracle') {
        expect($columns['id']['auto_increment'])->toBeTrue();
    }
});

it('defines portable version set indexes and foreign keys', function (): void {
    $table_name = CoreTables::VersionSets->value;
    $indexes = collect(Schema::getIndexes($table_name));
    $foreign_keys = collect(Schema::getForeignKeys($table_name));

    $uuid_index = $indexes->first(
        static fn (array $index): bool => $index['columns'] === ['uuid'],
    );
    $root_index = $indexes->first(
        static fn (array $index): bool => $index['columns'] === ['root_type', 'root_id'],
    );
    $reverted_from_foreign_key = $foreign_keys->first(
        static fn (array $foreign_key): bool => $foreign_key['columns'] === ['reverted_from_set_id'],
    );
    $user_foreign_key = config('versionable.user_foreign_key', 'user_id');

    expect($uuid_index)->not->toBeNull()
        ->and($uuid_index['name'])->toBe('vsets_uuid_un')
        ->and($uuid_index['unique'])->toBeTrue()
        ->and($root_index)->not->toBeNull()
        ->and($root_index['name'])->toBe('vsets_root_idx')
        ->and($indexes->pluck('name'))->toContain('vsets_created_idx')
        ->and($reverted_from_foreign_key)->not->toBeNull()
        ->and($reverted_from_foreign_key['foreign_table'])->toBe($table_name)
        ->and($reverted_from_foreign_key['foreign_columns'])->toBe(['id'])
        ->and(mb_strtolower((string) $reverted_from_foreign_key['on_delete']))->toBe('set null')
        ->and($foreign_keys->contains(
            static fn (array $foreign_key): bool => $foreign_key['columns'] === [$user_foreign_key],
        ))->toBeFalse();
});

it('keeps every new explicit index and foreign key name within portable limits', function (): void {
    $version_sets_migration = file_get_contents(
        dirname(__DIR__, 3) . '/database/migrations/2019_05_31_042933_create_version_sets_table.php',
    );
    $versions_migration = file_get_contents(
        dirname(__DIR__, 3) . '/database/migrations/2019_05_31_042934_create_versions_table.php',
    );
    $constraint_names = [
        'vsets_uuid_UN',
        'vsets_root_IDX',
        'vsets_created_IDX',
        'vsets_reverted_FK',
        'versions_set_sequence_UN',
        'versions_set_FK',
        'vepochs_root_fingerprint_UN',
        'versions_epoch_FK',
    ];

    expect($version_sets_migration)->toBeString()
        ->and($versions_migration)->toBeString();

    foreach ($constraint_names as $constraint_name) {
        expect(mb_strlen($constraint_name))->toBeLessThanOrEqual(30)
            ->and($version_sets_migration . $versions_migration)->toContain("'{$constraint_name}'");
    }
});

it('defines ordered version membership with cascading set deletion', function (): void {
    $table_name = CoreTables::Versions->value;
    $indexes = collect(Schema::getIndexes($table_name));
    $foreign_keys = collect(Schema::getForeignKeys($table_name));

    $sequence_index = $indexes->first(
        static fn (array $index): bool => $index['columns'] === ['version_set_id', 'sequence'],
    );
    $version_set_foreign_key = $foreign_keys->first(
        static fn (array $foreign_key): bool => $foreign_key['columns'] === ['version_set_id'],
    );

    expect($sequence_index)->not->toBeNull()
        ->and($sequence_index['name'])->toBe('versions_set_sequence_un')
        ->and($sequence_index['unique'])->toBeTrue()
        ->and($version_set_foreign_key)->not->toBeNull()
        ->and($version_set_foreign_key['foreign_table'])->toBe(CoreTables::VersionSets->value)
        ->and($version_set_foreign_key['foreign_columns'])->toBe(['id'])
        ->and(mb_strtolower((string) $version_set_foreign_key['on_delete']))->toBe('cascade');
});

it('requires ordered set membership while keeping relation metadata optional', function (): void {
    $columns = collect(Schema::getColumns(CoreTables::Versions->value))
        ->keyBy(static fn (array $column): string => mb_strtolower($column['name']));

    expect($columns['version_set_id']['nullable'])->toBeFalse()
        ->and($columns['sequence']['nullable'])->toBeFalse()
        ->and($columns['change_type']['nullable'])->toBeFalse()
        ->and($columns['relation_path']['nullable'])->toBeTrue()
        ->and($columns['subject_key']['nullable'])->toBeTrue();
});

it('rejects raw version rows without ordered set membership on sqlite', function (): void {
    if (Schema::getConnection()->getDriverName() !== 'sqlite') {
        expect(true)->toBeTrue();

        return;
    }

    expect(fn (): bool => DB::table(CoreTables::Versions->value)->insert([
        'versionable_type' => VersionSet::class,
        'versionable_id' => 1,
        'version_strategy' => VersionStrategy::DIFF->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('casts version set and version attributes to their domain types', function (): void {
    $version_set = VersionSet::query()->create([
        'uuid' => (string) Str::uuid(),
        'root_type' => VersionSet::class,
        'root_id' => '42',
        'kind' => VersionSetKind::Change,
    ]);
    $version = Version::query()->create([
        'version_set_id' => $version_set->getKey(),
        'sequence' => 1,
        'change_type' => VersionChangeType::Updated,
        'subject_key' => ['locale' => 'en', 'term_id' => 42],
        'versionable_type' => VersionSet::class,
        'versionable_id' => $version_set->getKey(),
        'original_contents' => ['name' => 'Before'],
        'contents' => ['name' => 'After'],
        'version_strategy' => VersionStrategy::DIFF,
    ]);

    expect($version_set->fresh()->kind)->toBe(VersionSetKind::Change)
        ->and($version_set->fresh()->created_at)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($version->fresh()->change_type)->toBe(VersionChangeType::Updated)
        ->and($version->fresh()->subject_key)->toBe(['locale' => 'en', 'term_id' => 42]);
});

it('mass assigns the configured version actor column', function (): void {
    $user_foreign_key = config('versionable.user_foreign_key', 'user_id');
    $version_set = VersionSet::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => VersionSetKind::Change,
        $user_foreign_key => 42,
    ]);

    expect((new VersionSet())->getFillable())->toContain($user_foreign_key)
        ->and($version_set->fresh()->getAttribute($user_foreign_key))->toBe(42);
});

it('orders versions by sequence and exposes both belongs-to relations', function (): void {
    $reverted_from = VersionSet::query()->create([
        'uuid' => (string) Str::uuid(),
        'root_type' => VersionSet::class,
        'root_id' => '42',
        'kind' => VersionSetKind::Change,
    ]);
    $version_set = VersionSet::query()->create([
        'uuid' => (string) Str::uuid(),
        'root_type' => VersionSet::class,
        'root_id' => '42',
        'kind' => VersionSetKind::Revert,
        'reverted_from_set_id' => $reverted_from->getKey(),
    ]);

    foreach ([2, 1] as $sequence) {
        Version::query()->create([
            'version_set_id' => $version_set->getKey(),
            'sequence' => $sequence,
            'change_type' => VersionChangeType::Updated,
            'versionable_type' => VersionSet::class,
            'versionable_id' => $version_set->getKey(),
            'original_contents' => [],
            'contents' => [],
            'version_strategy' => VersionStrategy::DIFF,
        ]);
    }

    expect($version_set->versions())->toBeInstanceOf(HasMany::class)
        ->and($version_set->versions()->pluck('sequence')->all())->toBe([1, 2])
        ->and($version_set->revertedFrom())->toBeInstanceOf(BelongsTo::class)
        ->and($version_set->revertedFrom->is($reverted_from))->toBeTrue()
        ->and($version_set->versions()->firstOrFail()->versionSet())->toBeInstanceOf(BelongsTo::class)
        ->and($version_set->versions()->firstOrFail()->versionSet->is($version_set))->toBeTrue();
});

it('names the history tables core_versions and core_versions_sets', function (): void {
    expect(CoreTables::Versions->value)->toBe('core_versions')
        ->and(CoreTables::VersionSets->value)->toBe('core_versions_sets')
        ->and(Schema::hasTable('core_versions'))->toBeTrue()
        ->and(Schema::hasTable('core_versions_sets'))->toBeTrue();
});

it('keeps the history tables append-only', function (): void {
    expect(Schema::hasColumns('core_versions', ['created_at', 'compacted_at']))->toBeTrue()
        ->and(Schema::hasColumn('core_versions', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('core_versions', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('core_versions', 'is_deleted'))->toBeFalse()
        ->and(Schema::hasColumn('core_versions_sets', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('core_versions_sets', 'updated_at'))->toBeFalse()
        ->and(Version::UPDATED_AT)->toBeNull()
        ->and(VersionSet::UPDATED_AT)->toBeNull();
});

it('sets reverted_from_set_id to null when the target set is removed', function (): void {
    $target = VersionSet::query()->forceCreate(['uuid' => (string) Str::uuid(), 'kind' => VersionSetKind::Change]);
    $revert = VersionSet::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'kind' => VersionSetKind::Revert,
        'reverted_from_set_id' => $target->getKey(),
    ]);

    $target->delete();

    expect($revert->fresh()->reverted_from_set_id)->toBeNull();
});

it('stores the capture quality of a set, best effort by default', function (): void {
    $set = VersionSet::query()->forceCreate(['uuid' => (string) Str::uuid(), 'kind' => VersionSetKind::Baseline]);

    expect($set->fresh()->capture)->toBe(VersionCaptureQuality::BestEffort)
        ->and($set->fresh()->kind)->toBe(VersionSetKind::Baseline);
});

it('creates the epochs table with one row per root class and fingerprint', function (): void {
    $table_name = CoreTables::VersionsEpochs->value;
    $columns = collect(Schema::getColumns($table_name))
        ->keyBy(static fn (array $column): string => mb_strtolower($column['name']));
    $unique = collect(Schema::getIndexes($table_name))->first(
        static fn (array $index): bool => $index['columns'] === ['root_type', 'root_table', 'fingerprint'],
    );

    expect($table_name)->toBe('core_versions_epochs')
        ->and(Schema::hasColumns($table_name, ['id', 'root_type', 'root_table', 'fingerprint', 'definition', 'first_seen_at']))->toBeTrue()
        ->and(Schema::hasColumn($table_name, 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn($table_name, 'deleted_at'))->toBeFalse()
        ->and($columns['root_type']['nullable'])->toBeFalse()
        ->and($columns['fingerprint']['nullable'])->toBeFalse()
        ->and($unique)->not->toBeNull()
        ->and($unique['name'])->toBe('vepochs_root_fingerprint_un')
        ->and($unique['unique'])->toBeTrue();
});

it('links every version row to an optional epoch that cannot be removed', function (): void {
    $columns = collect(Schema::getColumns(CoreTables::Versions->value))
        ->keyBy(static fn (array $column): string => mb_strtolower($column['name']));
    $epoch_foreign_key = collect(Schema::getForeignKeys(CoreTables::Versions->value))->first(
        static fn (array $foreign_key): bool => $foreign_key['columns'] === ['epoch_id'],
    );

    expect($columns['epoch_id']['nullable'])->toBeTrue()
        ->and($epoch_foreign_key)->not->toBeNull()
        ->and($epoch_foreign_key['foreign_table'])->toBe(CoreTables::VersionsEpochs->value)
        ->and($epoch_foreign_key['foreign_columns'])->toBe(['id'])
        ->and(mb_strtolower((string) $epoch_foreign_key['on_delete']))->toBeIn(['restrict', 'no action']);
});

it('refuses to delete an epoch a version row carries', function (): void {
    $epoch = VersionEpoch::factory()->create();
    $version_set = VersionSet::query()->forceCreate(['uuid' => (string) Str::uuid(), 'kind' => VersionSetKind::Change]);
    $version = Version::query()->create([
        'version_set_id' => $version_set->getKey(),
        'sequence' => 1,
        'change_type' => VersionChangeType::Updated,
        'versionable_type' => VersionSet::class,
        'versionable_id' => $version_set->getKey(),
        'version_strategy' => VersionStrategy::DIFF,
        'epoch_id' => $epoch->getKey(),
    ]);

    expect($version->fresh()->epoch->is($epoch))->toBeTrue()
        ->and(fn (): ?bool => $epoch->delete())->toThrow(QueryException::class);
});

it('casts the epoch definition and never updates an epoch row', function (): void {
    $epoch = VersionEpoch::factory()->create(['definition' => ['columns' => [['name' => 'title']]]]);

    expect($epoch->fresh()->definition)->toBe(['columns' => [['name' => 'title']]])
        ->and($epoch->fresh()->first_seen_at)->not->toBeNull()
        ->and(VersionEpoch::UPDATED_AT)->toBeNull();
});
