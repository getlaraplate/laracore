<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\PermissionManifest;
use Modules\Core\Casts\ActionEnum;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Console\PermissionsRefreshCommand;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\HelpersCache;
use Modules\Core\Models\OutboxEvent;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\Version;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Fixtures\PermissionsRefreshForcedHardDeleteModel;
use Modules\Core\Tests\Fixtures\PermissionsRefreshForcedUnlockableModel;
use Modules\Core\Tests\Fixtures\PermissionsRefreshLockableModel;
use Modules\Core\Tests\Fixtures\PermissionsRefreshNeverVersionedModel;
use Modules\Core\Tests\Fixtures\PermissionsRefreshPlainModel;
use Modules\Core\Tests\Stubs\Console\ConstructorConfiguredPermissionsModel;
use Modules\Core\Tests\Stubs\Console\DeclaredPermissionsStub;
use Modules\Core\Tests\Stubs\Versioning\VersionedDocument;
use Symfony\Component\Console\Application as SymfonyConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(function (): void {
    HelpersCache::clearModels();
    DeclaredPermissionsStub::$declared = [];
});

/**
 * @param  array<string, bool|string>  $options
 */
function runPermissionsRefreshForCoverage(array $options = []): string
{
    $command = app(PermissionsRefreshCommand::class);
    $command->setLaravel(app());
    $command->setApplication(new SymfonyConsoleApplication('coverage', '1.0.0'));
    $command->mergeApplicationDefinition();
    $input = new ArrayInput(
        array_merge(['command' => $command->getName()], $options),
        $command->getDefinition(),
    );
    $output = new BufferedOutput();
    $command->run($input, $output);

    return $output->fetch();
}

/**
 * @param  class-string<Illuminate\Database\Eloquent\Model>  $model_class
 */
function permissionNameForModel(string $model_class, ActionEnum $action): string
{
    $model = new $model_class();

    return PermissionName::forModel($model, $action->value);
}

/**
 * Stands in for the modules' permission declarations, so these tests do not depend on which
 * modules are installed or on what they declare: a real manifest whose only declaration is
 * the stub's, so no mock of the final class is needed.
 *
 * @param  array<class-string<Illuminate\Database\Eloquent\Model>, list<string>>  $declared_operations
 */
function fakeDeclaredPermissions(array $declared_operations): void
{
    DeclaredPermissionsStub::$declared = $declared_operations;
    $manifest = new PermissionManifest();
    new ReflectionProperty(PermissionManifest::class, 'declarations')->setValue($manifest, ['Stub' => DeclaredPermissionsStub::class]);
    app()->instance(PermissionManifest::class, $manifest);
}

it('command exists and has correct signature', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('permission:refresh');
    expect($source)->toContain('Refresh the Permission table');
});

it('command class has correct properties', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);

    expect($reflection->getName())->toBe('Modules\Core\Console\PermissionsRefreshCommand');
    expect($reflection->isSubclassOf(Modules\Core\Overrides\Command::class))->toBeTrue();
});

it('command can be instantiated', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);

    expect($reflection->isInstantiable())->toBeTrue();
    expect($reflection->isSubclassOf(Modules\Core\Overrides\Command::class))->toBeTrue();
});

it('command has correct namespace', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);

    expect($reflection->getNamespaceName())->toBe('Modules\Core\Console');
    expect($reflection->getShortName())->toBe('PermissionsRefreshCommand');
});

it('command has handle method', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);

    expect($reflection->hasMethod('handle'))->toBeTrue();
});

it('command handle method returns void', function (): void {
    $reflection = new ReflectionMethod(PermissionsRefreshCommand::class, 'handle');

    expect($reflection->getReturnType()->getName())->toBe('void');
});

it('command has pretend option', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('pretend');
    expect($source)->toContain('pretend_mode');
});

it('command handles blacklisted models', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('MODELS_BLACKLIST');
    expect($source)->toContain('checkIfBlacklisted');
});

it('command handles common permissions', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('common_permissions');
    expect($source)->toContain('ActionEnum::Select');
    expect($source)->toContain('ActionEnum::Insert');
    expect($source)->toContain('ActionEnum::Update');
    expect($source)->toContain('ActionEnum::Delete');
});

it('command handles soft delete permissions', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('SoftDeletes');
    expect($source)->toContain('ActionEnum::Restore');
    expect($source)->toContain('ActionEnum::ForceDelete');
});

it('command handles approval permissions', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('HasApprovals');
    expect($source)->toContain('ActionEnum::Approve');
});

it('command handles publish permissions', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('HasValidity');
    expect($source)->toContain('ActionEnum::Publish');
});

it('command handles lock permissions', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('ActionEnum::Lock');
});

it('command handles impersonate permission for users', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('ActionEnum::Impersonate');
    expect($source)->toContain('user_class');
});

it('command handles pretend mode', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('pretend_mode');
    expect($source)->toContain('Running in pretend mode');
});

it('command handles quiet mode', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('quiet_mode');
});

it('command handles permission creation', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('firstOrCreate(');
    expect($source)->toContain('query()');
});

it('command handles permission deletion', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('delete()');
    expect($source)->toContain('whereNotIn');
});

it('does not duplicate permissions when they already exist for the model table', function (): void {
    $permission_name = permissionNameForModel(PermissionsRefreshPlainModel::class, ActionEnum::Select);

    Permission::query()->firstOrCreate(
        ['name' => $permission_name],
        ['guard_name' => 'web'],
    );

    HelpersCache::setModels('active', [PermissionsRefreshPlainModel::class]);

    $output = runPermissionsRefreshForCoverage([]);

    expect(Permission::query()->where(['name' => $permission_name, 'guard_name' => 'web'])->count())->toBe(1)
        ->and($output)->not->toContain("Created '{$permission_name}' permission");
});

it('skips cached model classes whose files were moved or deleted', function (): void {
    // Simulate a stale HelpersCache entry for a model class that no longer exists.
    HelpersCache::setModels('active', [
        'Modules\\Removed\\Models\\Media',
        PermissionsRefreshPlainModel::class,
    ]);

    $exit_code = null;
    $output = null;

    expect(function () use (&$exit_code, &$output): void {
        $command = app(PermissionsRefreshCommand::class);
        $command->setLaravel(app());
        $command->setApplication(new SymfonyConsoleApplication('coverage', '1.0.0'));
        $command->mergeApplicationDefinition();
        $buffered = new BufferedOutput();
        $exit_code = $command->run(
            new ArrayInput(['command' => $command->getName()], $command->getDefinition()),
            $buffered,
        );
        $output = $buffered->fetch();
    })->not->toThrow(Throwable::class);

    expect($exit_code)->toBe(0)
        ->and($output)->not->toContain('Modules\\Removed\\Models\\Media');
});

it('uses connection and table configured by the model constructor', function (): void {
    $permission_name = 'permissions_constructor_connection.permissions_constructor_table.select';
    Permission::query()->where('name', $permission_name)->delete();
    HelpersCache::setModels('active', [ConstructorConfiguredPermissionsModel::class]);

    runPermissionsRefreshForCoverage([]);

    expect(Permission::query()->where('name', $permission_name)->exists())->toBeTrue();
});

it('command handles connection and table names', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('getConnectionName');
    expect($source)->toContain('getTable');
});

it('command handles permission naming convention', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('connection');
    expect($source)->toContain('table');
    expect($source)->toContain('permission_name');
});

it('command handles changes tracking', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('$changes');
    expect($source)->toContain('No changes needed');
});

it('command handles output messages', function (): void {
    $reflection = new ReflectionClass(PermissionsRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('Created');
    expect($source)->toContain('Deleted');
});

it('runs in pretend mode with merged quiet option', function (): void {
    HelpersCache::clearModels();
    HelpersCache::setModels('active', [User::class, Version::class]);

    $command = app(PermissionsRefreshCommand::class);
    $command->setLaravel(app());
    $command->setApplication(new SymfonyConsoleApplication('coverage', '1.0.0'));
    $command->mergeApplicationDefinition();

    $input = new ArrayInput([
        'command' => $command->getName(),
        '--pretend' => true,
        '--quiet' => true,
    ], $command->getDefinition());

    $exit = $command->run($input, new BufferedOutput());
    expect($exit)->toBe(0);

    HelpersCache::clearModels();
});

it('detects blacklisted models via checkIfBlacklisted helper', function (): void {
    $command = app(PermissionsRefreshCommand::class);
    $command->setLaravel(app());
    $method = new ReflectionMethod(PermissionsRefreshCommand::class, 'checkIfBlacklisted');
    $method->setAccessible(true);

    expect($method->invoke($command, Version::class))->toBeTrue()
        ->and($method->invoke($command, User::class))->toBeFalse();
});

it('runs permission refresh in pretend mode with output enabled', function (): void {
    HelpersCache::clearModels();
    HelpersCache::setModels('active', [User::class]);

    $command = app(PermissionsRefreshCommand::class);
    $command->setLaravel(app());
    $command->setApplication(new SymfonyConsoleApplication('coverage', '1.0.0'));
    $command->mergeApplicationDefinition();

    $output = new BufferedOutput();
    $input = new ArrayInput([
        'command' => $command->getName(),
        '--pretend' => true,
    ], $command->getDefinition());

    $exit = $command->run($input, $output);
    expect($exit)->toBe(0)
        ->and($output->fetch())->not->toBe('');

    HelpersCache::clearModels();
});

it('skips non-string and missing model entries and prints bypass for blacklisted classes', function (): void {
    HelpersCache::setModels('active', [
        404,
        'Definitely\\Not\\A\\LoadedModelClass',
        Version::class,
        PermissionsRefreshPlainModel::class,
    ]);

    $output = runPermissionsRefreshForCoverage([]);

    expect($output)->toContain(sprintf("Bypassing '%s' class", Version::class));
});

it('removes delete approve and publish permissions when the model does not support those features', function (): void {
    $delete_name = permissionNameForModel(PermissionsRefreshPlainModel::class, ActionEnum::Delete);
    $approve_name = permissionNameForModel(PermissionsRefreshPlainModel::class, ActionEnum::Approve);
    $publish_name = permissionNameForModel(PermissionsRefreshPlainModel::class, ActionEnum::Publish);

    Permission::query()->whereIn('name', [$delete_name, $approve_name, $publish_name])->delete();

    Permission::create(['name' => $delete_name, 'guard_name' => 'web']);
    Permission::create(['name' => $approve_name, 'guard_name' => 'web']);
    Permission::create(['name' => $publish_name, 'guard_name' => 'web']);

    HelpersCache::setModels('active', [PermissionsRefreshPlainModel::class]);

    $output = runPermissionsRefreshForCoverage([]);

    expect(Permission::query()->where('name', $delete_name)->count())->toBe(0);
    expect(Permission::query()->where('name', $approve_name)->count())->toBe(0);
    expect(Permission::query()->where('name', $publish_name)->count())->toBe(0);
    expect($output)->toContain("Deleted '{$delete_name}' permission");
    expect($output)->toContain("Deleted '{$approve_name}' permission");
});

/**
 * The condition behind this used to be `! usesTrait || $instance->softDeletesEnabled ?? true`,
 * which PHP reads as `(! usesTrait || $instance->softDeletesEnabled) ?? true`: the fallback
 * was dead and the property arm was inverted, so a model that switched soft deletes off in
 * its own code kept the two permissions it cannot use.
 */
it('removes delete and restore from a model that turns soft deletes off in its own code', function (): void {
    $delete_name = permissionNameForModel(PermissionsRefreshForcedHardDeleteModel::class, ActionEnum::Delete);
    $restore_name = permissionNameForModel(PermissionsRefreshForcedHardDeleteModel::class, ActionEnum::Restore);

    Permission::query()->whereIn('name', [$delete_name, $restore_name])->delete();
    Permission::create(['name' => $delete_name, 'guard_name' => 'web']);
    Permission::create(['name' => $restore_name, 'guard_name' => 'web']);

    HelpersCache::setModels('active', [PermissionsRefreshForcedHardDeleteModel::class]);

    runPermissionsRefreshForCoverage([]);

    expect(Permission::query()->whereIn('name', [$delete_name, $restore_name])->count())->toBe(0);
});

/**
 * Locking is a capability like soft deletes or approvals: the verbs belong to the
 * models that have the trait, and to nobody else. Before this branch existed `lock`
 * was generated for every managed table, which put 129 names on the role screen that
 * no code could ever reach, and `unlock` was generated for none, which left the CRUD
 * unlock check failing closed for everyone but a superadmin.
 */
it('removes lock and unlock from a model that cannot be locked', function (): void {
    $lock_name = permissionNameForModel(PermissionsRefreshPlainModel::class, ActionEnum::Lock);
    $unlock_name = permissionNameForModel(PermissionsRefreshPlainModel::class, ActionEnum::Unlock);

    Permission::query()->whereIn('name', [$lock_name, $unlock_name])->delete();
    Permission::create(['name' => $lock_name, 'guard_name' => 'web']);
    Permission::create(['name' => $unlock_name, 'guard_name' => 'web']);

    HelpersCache::setModels('active', [PermissionsRefreshPlainModel::class]);

    $output = runPermissionsRefreshForCoverage([]);

    expect(Permission::query()->whereIn('name', [$lock_name, $unlock_name])->count())->toBe(0)
        ->and($output)->toContain("Deleted '{$lock_name}' permission")
        ->and($output)->toContain("Deleted '{$unlock_name}' permission");
});

it('creates both verbs for a model that carries the locking trait', function (): void {
    $lock_name = permissionNameForModel(PermissionsRefreshLockableModel::class, ActionEnum::Lock);
    $unlock_name = permissionNameForModel(PermissionsRefreshLockableModel::class, ActionEnum::Unlock);

    Permission::query()->whereIn('name', [$lock_name, $unlock_name])->delete();

    HelpersCache::setModels('active', [PermissionsRefreshLockableModel::class]);

    runPermissionsRefreshForCoverage([]);

    expect(Permission::query()->where(['name' => $lock_name, 'guard_name' => 'web'])->count())->toBe(1)
        ->and(Permission::query()->where(['name' => $unlock_name, 'guard_name' => 'web'])->count())->toBe(1);
});

it('leaves both verbs out for a model that disables locking in its own code', function (): void {
    $lock_name = permissionNameForModel(PermissionsRefreshForcedUnlockableModel::class, ActionEnum::Lock);
    $unlock_name = permissionNameForModel(PermissionsRefreshForcedUnlockableModel::class, ActionEnum::Unlock);

    Permission::query()->whereIn('name', [$lock_name, $unlock_name])->delete();

    HelpersCache::setModels('active', [PermissionsRefreshForcedUnlockableModel::class]);

    runPermissionsRefreshForCoverage([]);

    expect(Permission::query()->whereIn('name', [$lock_name, $unlock_name])->count())->toBe(0);
});

/**
 * The `lock_{table}` setting is a runtime switch, not a statement about the model's
 * vocabulary. Deleting the permission when it is off would take every grant and every
 * ACL written on that name down with it by cascade, so the row stays and
 * {@see Modules\Core\Locking\Locked::usesHasLocks()} reads the setting at the point
 * of use instead.
 */
it('keeps the verbs when locking is only switched off in settings', function (): void {
    $lock_name = permissionNameForModel(PermissionsRefreshLockableModel::class, ActionEnum::Lock);
    $unlock_name = permissionNameForModel(PermissionsRefreshLockableModel::class, ActionEnum::Unlock);

    DB::table(CoreTables::Settings->value)->updateOrInsert(
        ['name' => PerModelSettingResolver::nameFor(CoreDatabaseSeeder::LOCK_NAME_PREFIX, new PermissionsRefreshLockableModel()->getTable())],
        [
            'value' => json_encode(false),
            'encrypted' => false,
            'type' => SettingTypeEnum::Boolean->value,
            'group_name' => 'locking',
            'description' => 'Lock status for the fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );

    app(PerModelSettingResolver::class)->flushGroup('locking');
    app(PerModelSettingResolver::class)->flushNameIndex();

    HelpersCache::setModels('active', [PermissionsRefreshLockableModel::class]);

    runPermissionsRefreshForCoverage([]);

    expect(Permission::query()->where(['name' => $lock_name, 'guard_name' => 'web'])->count())->toBe(1)
        ->and(Permission::query()->where(['name' => $unlock_name, 'guard_name' => 'web'])->count())->toBe(1);
});

it('drops permissions that no longer match any inspected model', function (): void {
    $orphan_name = (new Permission)->getConnection()->getName() . '.zz_' . bin2hex(random_bytes(4)) . '.select';

    Permission::create(['name' => $orphan_name, 'guard_name' => 'web']);

    HelpersCache::setModels('active', [PermissionsRefreshPlainModel::class]);

    $output = runPermissionsRefreshForCoverage([]);

    expect(Permission::query()->where('name', $orphan_name)->count())->toBe(0);
    expect($output)->toContain("Deleted '{$orphan_name}' permission");
});

it('reports no changes when a second run finds the permission set already in sync', function (): void {
    HelpersCache::setModels('active', [User::class]);

    runPermissionsRefreshForCoverage([]);
    HelpersCache::setModels('active', [User::class]);

    $output = runPermissionsRefreshForCoverage([]);

    expect($output)->toContain('No changes needed');
});

it('creates impersonate permission for the configured user model when missing', function (): void {
    $user_class = user_class();
    $impersonate_name = permissionNameForModel($user_class, ActionEnum::Impersonate);

    Permission::query()->where('name', $impersonate_name)->delete();

    HelpersCache::setModels('active', [user_class()]);

    $output = runPermissionsRefreshForCoverage([]);

    expect(Permission::query()->where('name', $impersonate_name)->exists())->toBeTrue();
    expect($output)->toContain($impersonate_name);
    expect($output)->toContain("Created '{$impersonate_name}' permission");
});

it('creates the domain permissions a module declares', function (): void {
    $permission_name = PermissionName::forClass(PermissionsRefreshPlainModel::class, 'post');
    fakeDeclaredPermissions([PermissionsRefreshPlainModel::class => ['post']]);

    Permission::query()->where('name', $permission_name)->delete();

    HelpersCache::setModels('active', [PermissionsRefreshPlainModel::class]);

    $output = runPermissionsRefreshForCoverage([]);

    expect(Permission::query()->where('name', $permission_name)->exists())->toBeTrue()
        ->and($output)->toContain("Created '{$permission_name}' declared permission");
});

it('keeps a declared permission whose verb collides with a generic one it would otherwise drop', function (): void {
    // `approve` is a generic verb the command drops for a model without the
    // approval trait, and at the same time a domain step a module may declare.
    $permission_name = permissionNameForModel(PermissionsRefreshPlainModel::class, ActionEnum::Approve);
    fakeDeclaredPermissions([PermissionsRefreshPlainModel::class => [ActionEnum::Approve->value]]);

    Permission::query()->firstOrCreate(['name' => $permission_name], ['guard_name' => 'web']);

    HelpersCache::setModels('active', [PermissionsRefreshPlainModel::class]);

    $output = runPermissionsRefreshForCoverage([]);

    expect(Permission::query()->where('name', $permission_name)->exists())->toBeTrue()
        ->and($output)->not->toContain("Deleted '{$permission_name}' permission");
});

it('leaves alone a permission whose operation it does not generate', function (): void {
    // Names also come from data: `sao_workflow_transitions.required_permission` is
    // free text checked with Gate::allows(). Pruning one would forbid the
    // transition for good.
    $orphan_name = 'default.zz_' . bin2hex(random_bytes(4)) . '.frobnicate';

    Permission::query()->create(['name' => $orphan_name, 'guard_name' => 'web']);

    HelpersCache::setModels('active', [PermissionsRefreshPlainModel::class]);

    $output = runPermissionsRefreshForCoverage([]);

    expect(Permission::query()->where('name', $orphan_name)->exists())->toBeTrue()
        ->and($output)->not->toContain($orphan_name);
});

it('generates no permission for a model a module excludes', function (): void {
    $table = (new OutboxEvent)->getTable();

    expect(app(PermissionManifest::class)->excludedModels())->toContain(OutboxEvent::class);

    Permission::query()->firstOrCreate(
        ['name' => PermissionName::forClass(OutboxEvent::class, ActionEnum::Select->value)],
        ['guard_name' => 'web'],
    );

    HelpersCache::setModels('active', [OutboxEvent::class]);

    runPermissionsRefreshForCoverage([]);

    expect(Permission::query()->where('table_name', $table)->count())->toBe(0);
});

it('creates every permission for the web and the api guard with the same name', function (): void {
    $select_name = permissionNameForModel(PermissionsRefreshPlainModel::class, ActionEnum::Select);
    $declared_name = PermissionName::forClass(PermissionsRefreshPlainModel::class, 'declared');
    fakeDeclaredPermissions([PermissionsRefreshPlainModel::class => ['declared']]);

    HelpersCache::setModels('active', [PermissionsRefreshPlainModel::class]);

    runPermissionsRefreshForCoverage([]);

    foreach ([$select_name, $declared_name] as $name) {
        expect(Permission::query()->where('name', $name)->orderBy('guard_name')->pluck('guard_name')->all())
            ->toBe(['api', 'web']);
    }
});

it('creates the api twin of a permission that exists on web only and is idempotent on a second run', function (): void {
    $permission_name = permissionNameForModel(PermissionsRefreshPlainModel::class, ActionEnum::Select);
    Permission::query()->where('name', $permission_name)->delete();
    Permission::query()->create(['name' => $permission_name, 'guard_name' => 'web']);

    HelpersCache::setModels('active', [PermissionsRefreshPlainModel::class]);

    runPermissionsRefreshForCoverage([]);
    $count_after_first_run = Permission::query()->count();
    $second_output = runPermissionsRefreshForCoverage([]);

    expect(Permission::query()->where('name', $permission_name)->count())->toBe(2)
        ->and(Permission::query()->where(['name' => $permission_name, 'guard_name' => 'api'])->exists())->toBeTrue()
        ->and(Permission::query()->count())->toBe($count_after_first_run)
        ->and($second_output)->not->toContain('Created');
});

it('generates no permission for the version history, which is read only through its root', function (): void {
    $table = (new Version)->getTable();

    expect(app(PermissionManifest::class)->excludedModels())->toContain(Version::class);

    Permission::query()->firstOrCreate(
        ['name' => PermissionName::forClass(Version::class, ActionEnum::Select->value)],
        ['guard_name' => 'web'],
    );

    HelpersCache::setModels('active', [Version::class]);

    runPermissionsRefreshForCoverage([]);

    expect(Permission::query()->where('table_name', $table)->count())->toBe(0);
});

it('creates history and revert on both guards for a versioned model only (D26, D27)', function (): void {
    fakeDeclaredPermissions([]);
    HelpersCache::setModels('active', [
        VersionedDocument::class,
        PermissionsRefreshPlainModel::class,
        PermissionsRefreshNeverVersionedModel::class,
    ]);

    runPermissionsRefreshForCoverage([]);

    foreach ([ActionEnum::History, ActionEnum::Revert] as $verb) {
        expect(Permission::query()->where('name', permissionNameForModel(VersionedDocument::class, $verb))->pluck('guard_name')->sort()->values()->all())->toBe(['api', 'web'])
            ->and(Permission::query()->where('name', permissionNameForModel(PermissionsRefreshPlainModel::class, $verb))->exists())->toBeFalse()
            ->and(Permission::query()->where('name', permissionNameForModel(PermissionsRefreshNeverVersionedModel::class, $verb))->exists())->toBeFalse();
    }
});
