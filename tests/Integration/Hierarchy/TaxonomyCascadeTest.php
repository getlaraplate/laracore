<?php

declare(strict_types=1);

use App\Casts\EntityType;
use App\Models\Entity;
use App\Models\Pivot\Presettable;
use App\Models\Preset;
use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Template;
use Modules\Core\Support\Hierarchy\ClosureTableMaintainer;
use Modules\Core\Tests\Stubs\DynamicEntities\StubEntityType;
use Modules\Core\Tests\Stubs\Hierarchy\HardDeletingModuleEntityStub;
use Modules\Core\Tests\Stubs\Hierarchy\ModuleEntityStub;
use Modules\Core\Tests\Support\AppDynamicEntities;

beforeEach(function (): void {
    $this->taxonomies = new ClosureTableMaintainer(DB::connection(), CoreTables::Taxonomies->value, CoreTables::TaxonomiesClosure->value);

    $this->otherPreset = AppDynamicEntities::preset(EntityType::Authors);
    $this->otherPresettable = taxonomyCascadePresettableOf($this->otherPreset);

    /**
     * Inserts a taxonomy row of the given entity and presettable, with its closure rows, returning its key.
     */
    $this->addTaxonomy = function (int $entityId, int $presettableId, ?int $parentId): int {
        $id = (int) DB::table(CoreTables::Taxonomies->value)->insertGetId([
            'entity_id' => $entityId,
            'presettable_id' => $presettableId,
            'parent_id' => $parentId,
        ]);
        $this->taxonomies->insertNode($id, $parentId);

        return $id;
    };

    /**
     * A thread under a taxonomy of the other owner: a node and its child owned by the doomed owner, each with a
     * reply of the other owner, the first reply with a leaf.
     *
     * @return array{top: int, doomed: list<int>, replies: list<int>, leaf: int}
     */
    $this->addThread = function (int $doomedEntityId, int $doomedPresettableId): array {
        $other_entity = (int) $this->otherPreset->entity_id;
        $other_presettable = (int) $this->otherPresettable->id;

        $top = ($this->addTaxonomy)($other_entity, $other_presettable, null);
        $doomed = ($this->addTaxonomy)($doomedEntityId, $doomedPresettableId, $top);
        $reply = ($this->addTaxonomy)($other_entity, $other_presettable, $doomed);
        $leaf = ($this->addTaxonomy)($other_entity, $other_presettable, $reply);
        $nested_doomed = ($this->addTaxonomy)($doomedEntityId, $doomedPresettableId, $doomed);
        $nested_reply = ($this->addTaxonomy)($other_entity, $other_presettable, $nested_doomed);

        return ['top' => $top, 'doomed' => [$doomed, $nested_doomed], 'replies' => [$reply, $nested_reply], 'leaf' => $leaf];
    };
});

/**
 * The live presettable of a preset, written by the presets insert trigger.
 */
function taxonomyCascadePresettableOf(Preset $preset): Presettable
{
    return Presettable::query()->where('preset_id', $preset->id)->latest('version')->firstOrFail();
}

/**
 * Every taxonomy closure row as "ancestor>descendant:depth", sorted.
 *
 * @return list<string>
 */
function taxonomyCascadeClosureRows(): array
{
    $rows = DB::table(CoreTables::TaxonomiesClosure->value)->get()
        ->map(static fn (object $row): string => sprintf('%d>%d:%d', $row->ancestor_id, $row->descendant_id, $row->depth))
        ->all();
    sort($rows);

    return $rows;
}

/**
 * The thread after the owner's hard delete: the doomed taxonomies are gone, the replies are roots, and the closure
 * equals a rebuild from the parent column.
 *
 * @param  array{top: int, doomed: list<int>, replies: list<int>, leaf: int}  $thread
 */
function taxonomyCascadeExpectRepliesAreRoots(ClosureTableMaintainer $taxonomies, array $thread): void
{
    $maintained = taxonomyCascadeClosureRows();

    expect(DB::table(CoreTables::Taxonomies->value)->whereIn('id', $thread['doomed'])->exists())->toBeFalse()
        ->and(DB::table(CoreTables::Taxonomies->value)->whereIn('id', $thread['replies'])->pluck('parent_id')->all())->toBe([null, null])
        ->and(DB::table(CoreTables::TaxonomiesClosure->value)->where('ancestor_id', $thread['top'])->pluck('descendant_id')->all())->toBe([$thread['top']])
        ->and($maintained)->toContain(sprintf('%d>%d:1', $thread['replies'][0], $thread['leaf']));

    $taxonomies->rebuild();

    expect(taxonomyCascadeClosureRows())->toBe($maintained);
}

it('roots the taxonomies left by an owner hard delete', function (Closure $owner): void {
    /** @var array{entity: int, presettable: int, delete: Closure(): void} $doomed */
    $doomed = $owner->call($this);
    $thread = ($this->addThread)($doomed['entity'], $doomed['presettable']);

    ($doomed['delete'])();

    taxonomyCascadeExpectRepliesAreRoots($this->taxonomies, $thread);
})->with([
    'entity, by entity_id' => [function (): array {
        $entity = AppDynamicEntities::entity(EntityType::Pages);

        return ['entity' => (int) $entity->id, 'presettable' => (int) $this->otherPresettable->id, 'delete' => static fn () => expect($entity->forceDelete())->toBeTrue()];
    }],
    'presettable, by presettable_id' => [function (): array {
        $presettable = taxonomyCascadePresettableOf(AppDynamicEntities::preset(EntityType::Pages));

        // Core SoftDeletes::delete() declares ?bool while a pivot's delete returns the row count: the row goes and
        // the events run, then the return type throws. Pre-existing, outside this test's subject.
        return ['entity' => (int) $this->otherPreset->entity_id, 'presettable' => (int) $presettable->id, 'delete' => static fn () => expect(static fn () => $presettable->forceDelete())
            ->toThrow(TypeError::class, 'must be of type ?bool, int returned')];
    }],
    'preset, through its presettables' => [function (): array {
        $preset = AppDynamicEntities::preset(EntityType::Pages);

        return ['entity' => (int) $this->otherPreset->entity_id, 'presettable' => (int) taxonomyCascadePresettableOf($preset)->id, 'delete' => static fn () => expect($preset->forceDelete())->toBeTrue()];
    }],
    'template, through its presets' => [function (): array {
        $template = Template::query()->create(['name' => 'cascade', 'content' => '<p>cascade</p>']);
        $preset = Preset::query()->create(['entity_id' => AppDynamicEntities::entity(EntityType::Pages)->id, 'name' => 'templated', 'template_id' => $template->id]);

        return ['entity' => (int) $this->otherPreset->entity_id, 'presettable' => (int) taxonomyCascadePresettableOf($preset)->id, 'delete' => static fn () => expect($template->forceDelete())->toBeTrue()];
    }],
]);

it('roots the taxonomies left by the hard delete of a module subclass of the abstract entity', function (): void {
    $entity = ModuleEntityStub::query()->create(['name' => 'module entity', 'slug' => 'module-entity', 'type' => StubEntityType::Pages]);
    $thread = ($this->addThread)((int) $entity->id, (int) $this->otherPresettable->id);

    expect($entity->forceDelete())->toBeTrue();

    taxonomyCascadeExpectRepliesAreRoots($this->taxonomies, $thread);
});

it('roots the taxonomies when the owner delete removes the row with soft deletes switched off', function (): void {
    $entity = HardDeletingModuleEntityStub::query()->create(['name' => 'hard entity', 'slug' => 'hard-entity', 'type' => StubEntityType::Pages]);
    $thread = ($this->addThread)((int) $entity->id, (int) $this->otherPresettable->id);

    expect($entity->delete())->toBeTrue()
        ->and(DB::table(CoreTables::Entities->value)->where('id', $entity->id)->exists())->toBeFalse();

    taxonomyCascadeExpectRepliesAreRoots($this->taxonomies, $thread);
});

it('leaves the closure as it is when the owner only goes to the bin', function (): void {
    $entity = AppDynamicEntities::entity(EntityType::Pages);
    ($this->addThread)((int) $entity->id, (int) $this->otherPresettable->id);
    $before = taxonomyCascadeClosureRows();

    expect($entity->delete())->toBeTrue()
        ->and(taxonomyCascadeClosureRows())->toBe($before);
});

it('leaves the closure as it is when a later listener cancels the owner delete', function (): void {
    $entity = AppDynamicEntities::entity(EntityType::Pages);
    $thread = ($this->addThread)((int) $entity->id, (int) $this->otherPresettable->id);
    $before = taxonomyCascadeClosureRows();
    Entity::deleting(static fn (): bool => false);

    expect($entity->forceDelete())->toBeFalse()
        ->and(DB::table(CoreTables::Taxonomies->value)->whereIn('id', $thread['doomed'])->count())->toBe(2)
        ->and(taxonomyCascadeClosureRows())->toBe($before);
});
