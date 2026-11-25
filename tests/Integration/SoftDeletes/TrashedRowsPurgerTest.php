<?php

declare(strict_types=1);

use App\Casts\EntityType as AppEntityType;
use App\Models\Entity as AppEntity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\HelpersCache;
use Modules\Core\Helpers\MigrateUtils;
use Modules\Core\SoftDeletes\TrashedRowsPurger;
use Modules\Core\Support\Hierarchy\ClosureTableMaintainer;
use Modules\Core\Tests\Stubs\SoftDeletesStubModel;
use Modules\Core\Tests\Stubs\SoftDeletingClosureTreeStubModel;
use Modules\Core\Tests\Support\AppDynamicEntities;

/*
 * The rows of a table in the bin are deleted for good through their model, so their own events run, whether the model
 * still soft deletes or no longer does; a table no model reads loses them with a plain delete.
 */

beforeEach(function (): void {
    Schema::create('soft_deletes_stub', static function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
        $table->boolean('is_deleted')->default(false);
    });
    Schema::create('soft_deletes_other_stub', static function (Blueprint $table): void {
        $table->id();
        $table->timestamp('deleted_at')->nullable();
    });
    DB::table('soft_deletes_stub')->insert([
        ['name' => 'live', 'deleted_at' => null, 'is_deleted' => false],
        ['name' => 'trashed', 'deleted_at' => now(), 'is_deleted' => true],
        ['name' => 'flagged only', 'deleted_at' => null, 'is_deleted' => true],
    ]);
    DB::table('soft_deletes_other_stub')->insert([['deleted_at' => now()]]);
});

afterEach(function (): void {
    HelpersCache::clearModels();
    Schema::dropIfExists('soft_deletes_stub');
    Schema::dropIfExists('soft_deletes_other_stub');
});

it('force deletes every row of the table in the bin through its model, and nothing else', function (): void {
    $force_deleted = [];
    Event::listen('eloquent.forceDeleted: ' . SoftDeletesStubModel::class, static function (SoftDeletesStubModel $model) use (&$force_deleted): void {
        $force_deleted[] = $model->name;
    });
    $purger = app(TrashedRowsPurger::class);

    $count = $purger->count(new SoftDeletesStubModel());
    $purged = $purger->purge(new SoftDeletesStubModel());

    expect($count)->toBe(2)
        ->and($purged)->toBe(2)
        ->and($force_deleted)->toEqualCanonicalizing(['trashed', 'flagged only'])
        ->and(DB::table('soft_deletes_stub')->pluck('name')->all())->toBe(['live'])
        ->and(DB::table('soft_deletes_other_stub')->count())->toBe(1);
});

it('finds the model of a table among the application models, and purges a table no model reads with a plain delete', function (): void {
    HelpersCache::setModels('active', [...models(), SoftDeletesStubModel::class]);
    $purger = app(TrashedRowsPurger::class);

    expect($purger->countFor('soft_deletes_stub'))->toBe(2)
        ->and($purger->purgeTable('soft_deletes_stub'))->toBe(2)
        ->and($purger->countFor('soft_deletes_other_stub'))->toBe(0)
        ->and($purger->purgeTable('soft_deletes_other_stub'))->toBe(1)
        ->and(DB::table('soft_deletes_other_stub')->count())->toBe(0)
        ->and($purger->purgeTable('no_such_table'))->toBe(0);
});

it('purges a table no model reads whatever its key, in one statement, and flushes it', function (): void {
    Schema::create('soft_deletes_coded_stub', static function (Blueprint $table): void {
        $table->string('code')->primary();
        $table->timestamp('deleted_at')->nullable();
    });
    DB::table('soft_deletes_coded_stub')->insert([['code' => 'a', 'deleted_at' => now()], ['code' => 'b', 'deleted_at' => null]]);
    $index = new Modules\Core\Tests\Stubs\Cache\RecordingLabelIndex();
    app()->instance(Modules\Core\Contracts\ICacheLabelIndex::class, $index);

    try {
        expect(app(TrashedRowsPurger::class)->purgeTable('soft_deletes_coded_stub'))->toBe(1)
            ->and(DB::table('soft_deletes_coded_stub')->pluck('code')->all())->toBe(['b'])
            ->and($index->flushedLabels())->toContain(Modules\Core\Cache\Labels\CacheLabel::table('soft_deletes_coded_stub'), Modules\Core\Cache\Labels\CacheLabel::rows('soft_deletes_coded_stub'));
    } finally {
        Schema::dropIfExists('soft_deletes_coded_stub');
    }
});

it('writes a remove migration that purges the rows in the bin before it drops the columns', function (): void {
    $stub = (string) file_get_contents(module_path('Core', 'app/SoftDeletes/Stubs/remove_soft_deletes_columns_from_table.stub'));
    $path = storage_path('framework/testing/remove_soft_deletes_' . uniqid() . '.php');
    (new Filesystem())->ensureDirectoryExists(dirname($path));
    file_put_contents($path, str_replace('$ModelTable$', 'soft_deletes_stub', $stub));

    try {
        $migration = require $path;
        $migration->up();
    } finally {
        @unlink($path);
    }

    expect(DB::table('soft_deletes_stub')->pluck('name')->all())->toBe(['live'])
        ->and(Schema::hasColumn('soft_deletes_stub', 'deleted_at'))->toBeFalse();
});

it('keeps the closure of a hierarchical model in step with its purge', function (): void {
    Schema::dropIfExists('closure_soft_tree_nodes_closure');
    Schema::dropIfExists('closure_soft_tree_nodes');
    Schema::create('closure_soft_tree_nodes', function (Blueprint $table): void {
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

    $purged = app(TrashedRowsPurger::class)->purge(new SoftDeletingClosureTreeStubModel);

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

it('keeps in step the closures of the hierarchies its purge of an owner reaches by cascade', function (): void {
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

    $purged = app(TrashedRowsPurger::class)->purge(new AppEntity);

    expect($purged)->toBe(1)
        ->and(DB::table(CoreTables::Taxonomies->value)->where('id', $reply)->value('parent_id'))->toBeNull()
        ->and(DB::table(CoreTables::TaxonomiesClosure->value)->orderBy('ancestor_id')->get()
            ->map(static fn (object $row): string => sprintf('%d>%d:%d', $row->ancestor_id, $row->descendant_id, $row->depth))
            ->all())->toBe([
                sprintf('%d>%d:0', $top, $top),
                sprintf('%d>%d:0', $reply, $reply),
            ]);
});
