<?php

declare(strict_types=1);

use App\Casts\EntityType as AppEntityType;
use App\Models\Entity as AppEntity;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\MigrateUtils;
use Modules\Core\SoftDeletes\Console\ModelSoftDeletesAddCommand;
use Modules\Core\SoftDeletes\Console\ModelSoftDeletesRefreshCommand;
use Modules\Core\SoftDeletes\Console\ModelSoftDeletesRemoveCommand;
use Modules\Core\Support\Hierarchy\ClosureTableMaintainer;
use Modules\Core\Support\Hierarchy\OwnerCascadeRegistry;
use Modules\Core\Tests\Stubs\Console\ConstructorConfiguredSoftDeleteModel;
use Modules\Core\Tests\Stubs\SoftDeletingClosureTreeStubModel;
use Modules\Core\Tests\Support\AppDynamicEntities;
use Symfony\Component\Console\Application as SymfonyConsoleApplication;

it('defines expected command signatures', function (): void {
    $add = new ReflectionClass(ModelSoftDeletesAddCommand::class);
    $remove = new ReflectionClass(ModelSoftDeletesRemoveCommand::class);
    $refresh = new ReflectionClass(ModelSoftDeletesRefreshCommand::class);

    expect(file_get_contents($add->getFileName()))->toContain('model:soft-deletes-add')
        ->and(file_get_contents($remove->getFileName()))->toContain('model:soft-deletes-remove')
        ->and(file_get_contents($refresh->getFileName()))->toContain('model:soft-deletes-refresh');
});

it('remove command includes mandatory purge safeguard for logical deletions', function (): void {
    $reflection = new ReflectionClass(ModelSoftDeletesRemoveCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('logical-deleted records')
        ->and($source)->toContain('Purge those records with hard delete before removing soft delete columns?')
        ->and($source)->toContain('Operation cancelled. No columns were removed.');
});

it('refresh command reconciles trait-schema-setting consistency', function (): void {
    $reflection = new ReflectionClass(ModelSoftDeletesRefreshCommand::class);
    $source = file_get_contents($reflection->getFileName());

    // The trailing underscore now lives in PerModelSettingResolver::nameFor(),
    // which is the single place allowed to join prefix and table.
    expect($source)->toContain('model:soft-deletes-add')
        ->and($source)->toContain('model:soft-deletes-remove')
        ->and($source)->toContain('soft_deletes');
});

it('refresh command merges application quiet option without duplicate definition', function (): void {
    $command = app(ModelSoftDeletesRefreshCommand::class);
    $command->setLaravel(app());
    $command->setApplication(new SymfonyConsoleApplication('coverage', '1.0.0'));
    $command->mergeApplicationDefinition();

    expect($command->getDefinition()->hasOption('quiet'))->toBeTrue();
});

it('instantiates the soft-delete model through its constructor', function (): void {
    $command = new ModelSoftDeletesRemoveCommand(new Filesystem, app(OwnerCascadeRegistry::class));
    $method = new ReflectionMethod($command, 'instantiateModel');
    $model = $method->invoke($command, new ReflectionClass(ConstructorConfiguredSoftDeleteModel::class));

    expect($model)->toBeInstanceOf(ConstructorConfiguredSoftDeleteModel::class)
        ->and($model->getConnectionName())->toBe('soft_delete_constructor_connection')
        ->and($model->getTable())->toBe('soft_delete_constructor_table');
});

it('refresh inspects schema through the constructor-configured model connection', function (): void {
    config()->set('database.connections.soft_delete_constructor_connection', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    DB::purge('soft_delete_constructor_connection');
    $connection = DB::connection('soft_delete_constructor_connection');
    $connection->getSchemaBuilder()->create('soft_delete_constructor_table', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
    });
    $connection->enableQueryLog();

    $command = app(ModelSoftDeletesRefreshCommand::class);
    $method = new ReflectionMethod($command, 'checkModel');
    $method->setAccessible(true);

    try {
        $method->invoke($command, ConstructorConfiguredSoftDeleteModel::class);

        expect(json_encode($connection->getQueryLog(), JSON_THROW_ON_ERROR))
            ->toContain('soft_delete_constructor_table');
    } finally {
        DB::disconnect('soft_delete_constructor_connection');
        DB::purge('soft_delete_constructor_connection');
    }
});

it('writes soft-delete settings through the Setting model connection', function (): void {
    $previous_default = config('database.default');
    $resolver = Model::getConnectionResolver();

    config()->set('database.connections.soft_delete_default', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('database.connections.soft_delete_affinity', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    DB::purge('soft_delete_default');
    DB::purge('soft_delete_affinity');
    DB::setDefaultConnection('soft_delete_default');

    Schema::connection('soft_delete_affinity')->create(CoreTables::Settings->value, function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->string('name')->unique();
        $table->text('value')->nullable();
        $table->boolean('encrypted')->default(false);
        $table->boolean('is_internal')->default(false);
        $table->boolean('managed')->default(false);
        $table->boolean('is_public')->default(false);
        $table->json('choices')->nullable();
        $table->string('type');
        $table->string('group_name');
        $table->string('description')->nullable();
        $table->string('action_command')->nullable();
        $table->boolean('action_queued')->default(false);
        $table->boolean('is_deleted')->default(false);
        $table->timestamps();
    });

    // Saving a Setting runs the permission check in HasValidations and the
    // approval capture in RequiresApproval, so the probe connection needs those
    // tables too. They stay empty: the point of the test is connection affinity.
    Schema::connection('soft_delete_affinity')->create('vend_permissions', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('guard_name')->default('web');
        $table->timestamps();
    });
    Schema::connection('soft_delete_affinity')->create('core_modifications', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->string('modifiable_type')->nullable();
        $table->string('modifiable_id')->nullable();
        $table->json('modifications')->nullable();
        $table->string('md5')->nullable();
        $table->boolean('active')->default(true);
        $table->string('operation', 16)->default('create');
        $table->timestamps();
    });

    Model::setConnectionResolver(new class($resolver) implements ConnectionResolverInterface
    {
        public function __construct(private readonly ConnectionResolverInterface $resolver) {}

        public function connection($name = null): Illuminate\Database\ConnectionInterface
        {
            return $name === null ? DB::connection('soft_delete_affinity') : $this->resolver->connection($name);
        }

        public function getDefaultConnection(): string
        {
            return $this->resolver->getDefaultConnection();
        }

        public function setDefaultConnection($name): void
        {
            $this->resolver->setDefaultConnection($name);
        }
    });

    $command = new ModelSoftDeletesRemoveCommand(new Filesystem, app(OwnerCascadeRegistry::class));
    $method = new ReflectionMethod($command, 'upsertSoftDeletesSetting');
    $method->setAccessible(true);

    try {
        expect($method->invoke($command, 'affinity_table', true))->toBeTrue()
            ->and(DB::connection('soft_delete_affinity')->table(CoreTables::Settings->value)
                ->where('name', 'soft_deletes.enabled.affinity_table')
                ->exists())->toBeTrue();
    } finally {
        Model::setConnectionResolver($resolver);
        DB::setDefaultConnection($previous_default);
        DB::disconnect('soft_delete_default');
        DB::purge('soft_delete_default');
        DB::disconnect('soft_delete_affinity');
        DB::purge('soft_delete_affinity');
    }
});

it('rebuilds the closure after purging a hierarchical model', function (): void {
    Schema::dropIfExists('closure_soft_tree_nodes_closure');
    Schema::dropIfExists('closure_soft_tree_nodes');
    Schema::create('closure_soft_tree_nodes', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->foreignId('parent_id')->nullable()->constrained('closure_soft_tree_nodes')->nullOnDelete();
        $table->softDeletes();
        $table->boolean('is_deleted')->storedAs('deleted_at IS NOT NULL');
        $table->timestamps();
    });
    MigrateUtils::closureTable('closure_soft_tree_nodes');

    $root = SoftDeletingClosureTreeStubModel::query()->create(['parent_id' => null]);
    $middle = SoftDeletingClosureTreeStubModel::query()->create(['parent_id' => $root->id]);
    $leaf = SoftDeletingClosureTreeStubModel::query()->create(['parent_id' => $middle->id]);
    $middle->delete();

    $command = new ModelSoftDeletesRemoveCommand(new Filesystem, app(OwnerCascadeRegistry::class));
    $method = new ReflectionMethod($command, 'purgeSoftDeletedRows');
    $purged = $method->invoke($command, new SoftDeletingClosureTreeStubModel);

    $rows = static fn (): array => DB::table('closure_soft_tree_nodes_closure')
        ->orderBy('ancestor_id')->orderBy('descendant_id')->get()
        ->map(static fn (object $row): string => sprintf('%d>%d:%d', $row->ancestor_id, $row->descendant_id, $row->depth))
        ->all();
    $after_purge = $rows();
    SoftDeletingClosureTreeStubModel::rebuildClosure();

    try {
        expect($purged)->toBe(1)
            ->and($after_purge)->toBe($rows())
            ->and($after_purge)->toBe([
                sprintf('%d>%d:0', $root->id, $root->id),
                sprintf('%d>%d:0', $leaf->id, $leaf->id),
            ]);
    } finally {
        Schema::dropIfExists('closure_soft_tree_nodes_closure');
        Schema::dropIfExists('closure_soft_tree_nodes');
    }
});

it('rebuilds the closures of the hierarchies its purge of an owner reached by cascade', function (): void {
    $taxonomies = new ClosureTableMaintainer(DB::connection(), CoreTables::Taxonomies->value, CoreTables::TaxonomiesClosure->value);
    $other_preset = AppDynamicEntities::preset(AppEntityType::Authors);
    $other_presettable = (int) DB::table(CoreTables::Presettables->value)->where('preset_id', $other_preset->id)->value('id');
    $trashed_entity = AppDynamicEntities::entity(AppEntityType::Pages);
    $add = static function (int $entityId, ?int $parentId) use ($taxonomies, $other_presettable): int {
        $id = (int) DB::table(CoreTables::Taxonomies->value)->insertGetId(['entity_id' => $entityId, 'presettable_id' => $other_presettable, 'parent_id' => $parentId]);
        $taxonomies->insertNode($id, $parentId);

        return $id;
    };
    $top = $add((int) $other_preset->entity_id, null);
    $doomed = $add((int) $trashed_entity->id, $top);
    $reply = $add((int) $other_preset->entity_id, $doomed);
    $trashed_entity->delete();

    $command = new ModelSoftDeletesRemoveCommand(new Filesystem, app(OwnerCascadeRegistry::class));
    $purged = (new ReflectionMethod($command, 'purgeSoftDeletedRows'))->invoke($command, new AppEntity);

    expect($purged)->toBe(1)
        ->and(DB::table(CoreTables::Taxonomies->value)->where('id', $reply)->value('parent_id'))->toBeNull()
        ->and(DB::table(CoreTables::TaxonomiesClosure->value)->orderBy('ancestor_id')->get()
            ->map(static fn (object $row): string => sprintf('%d>%d:%d', $row->ancestor_id, $row->descendant_id, $row->depth))
            ->all())->toBe([
                sprintf('%d>%d:0', $top, $top),
                sprintf('%d>%d:0', $reply, $reply),
            ]);
});
