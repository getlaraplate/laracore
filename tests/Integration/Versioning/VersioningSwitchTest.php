<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Jobs\PurgeVersionHistoryJob;
use Modules\Core\Models\Setting;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationRoot;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationSubject;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocument;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocumentTranslation;
use Modules\Core\Versioning\VersionHistoryPurger;

/**
 * Switching it back on starts a new history: the first save of each record writes a baseline of its whole aggregate,
 * the save's own row, the root, every locale and every link, as it is after the save (D22, D45).
 *
 * Switching a table's versioning off removes its edit history and keeps the snapshot sets of the records deleted for
 * good (decision D22 of spec 2026-07-29-revision-centric-aggregate-history-design.md). The purge is a queued job,
 * dispatched by any save that switches the setting off, that checks the setting again when it runs. It works by
 * table, so every root class stored in the table loses its history.
 */
beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);

    Schema::create(TranslatedDocument::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('code')->nullable();
        $table->unsignedInteger('lock_version')->default(1);
        $table->timestamps();
    });
    Schema::create(TranslatedDocumentTranslation::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->foreignId('translated_document_id')->constrained(TranslatedDocument::TABLE)->cascadeOnDelete();
        $table->string('locale');
        $table->string('title')->nullable();
        $table->string('body')->nullable();
        $table->timestamps();
    });
    Schema::create(VersionedRelationRoot::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });
    Schema::create(VersionedRelationSubject::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create(VersionedRelationRoot::PIVOT_TABLE, function (Blueprint $table): void {
        $table->unsignedBigInteger('root_id');
        $table->unsignedBigInteger('subject_id');
        $table->unsignedInteger('position')->default(0);
    });
});

afterEach(function (): void {
    Schema::dropIfExists(VersionedRelationRoot::PIVOT_TABLE);
    Schema::dropIfExists(VersionedRelationSubject::TABLE);
    Schema::dropIfExists(VersionedRelationRoot::TABLE);
    Schema::dropIfExists(TranslatedDocumentTranslation::TABLE);
    Schema::dropIfExists(TranslatedDocument::TABLE);
});

/**
 * @return Illuminate\Database\Eloquent\Collection<int, VersionSet>
 */
function switch_sets_of(Model $root): Illuminate\Database\Eloquent\Collection
{
    return VersionSet::query()
        ->where('root_type', $root->getMorphClass())
        ->where('root_id', (string) $root->getKey())
        ->get();
}

/**
 * The versioning strategy setting of the documents table, stored as the seeder stores it.
 */
function switch_setting(mixed $value, string $table = TranslatedDocument::TABLE): Setting
{
    return Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'versioning.strategy.' . $table,
        'type' => 'json',
        'value' => $value,
        'choices' => [false, ...VersionStrategy::cases()],
        'group_name' => 'versioning',
    ]);
}

function switch_setting_to(Setting $setting, mixed $value): void
{
    $setting->setSkipValidation(true);
    $setting->setForcedApprovalUpdate(true);
    $setting->value = $value;
    $setting->save();
}

it('purges the edit history of a table and keeps the snapshot of a record deleted for good', function (): void {
    $edited = TranslatedDocument::query()->create(['code' => 'edited', 'title' => 'Title']);
    $edited->code = 'changed';
    $edited->save();
    $deleted = TranslatedDocument::query()->create(['code' => 'deleted', 'title' => 'Title']);
    $deleted->delete();
    $other_table = VersionedRelationRoot::query()->create(['title' => 'Elsewhere']);
    $purger = app(VersionHistoryPurger::class);

    $edit_sets = switch_sets_of($edited);
    $counts = $purger->countFor(TranslatedDocument::TABLE);

    expect($counts)->toBe([
        'sets' => $edit_sets->count(),
        'rows' => Version::query()->whereIn('version_set_id', $edit_sets->modelKeys())->count(),
    ])->and($purger->purge(TranslatedDocument::TABLE))->toBe($edit_sets->count())
        ->and(switch_sets_of($edited))->toHaveCount(0)
        ->and(Version::query()->whereIn('version_set_id', $edit_sets->modelKeys())->exists())->toBeFalse()
        ->and(switch_sets_of($deleted))->toHaveCount(1)
        ->and(switch_sets_of($other_table))->not->toHaveCount(0)
        ->and($purger->countFor(TranslatedDocument::TABLE))->toBe(['sets' => 0, 'rows' => 0]);
});

it('purges by table: every root class stored in the table loses its history', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $shared = new VersionSet;
    $shared->forceFill([
        'uuid' => (string) Str::uuid(),
        'root_type' => 'core.another_root_of_the_same_table',
        'root_id' => '1',
        'root_connection_ref' => $document->getConnectionName(),
        'root_table_ref' => TranslatedDocument::TABLE,
        'kind' => VersionSetKind::Change,
        'capture' => VersionCaptureQuality::Atomic,
    ])->save();

    app(VersionHistoryPurger::class)->purge(TranslatedDocument::TABLE);

    expect(switch_sets_of($document))->toHaveCount(0)
        ->and(VersionSet::query()->find($shared->getKey()))->toBeNull();
});

it('queues the purge when a save switches the versioning of a table off, and only then', function (): void {
    Bus::fake();
    $setting = switch_setting(VersionStrategy::DIFF->value);

    switch_setting_to($setting, VersionStrategy::SNAPSHOT->value);
    Bus::assertNotDispatched(PurgeVersionHistoryJob::class);

    switch_setting_to($setting, false);
    Bus::assertDispatched(
        PurgeVersionHistoryJob::class,
        static fn (PurgeVersionHistoryJob $job): bool => $job->table === TranslatedDocument::TABLE && $job->settingName === $setting->name,
    );
    Bus::assertDispatchedTimes(PurgeVersionHistoryJob::class, 1);

    $setting->description = 'Still off';
    switch_setting_to($setting, false);
    switch_setting_to($setting, VersionStrategy::DIFF->value);

    Bus::assertDispatchedTimes(PurgeVersionHistoryJob::class, 1);
});

it('purges when the job runs with versioning still off', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    switch_setting(false);

    app()->call([new PurgeVersionHistoryJob(TranslatedDocument::TABLE, 'versioning.strategy.' . TranslatedDocument::TABLE), 'handle']);

    expect(switch_sets_of($document))->toHaveCount(0);
});

it('keeps the history when versioning is back on by the time the job runs', function (): void {
    Bus::fake();
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $setting = switch_setting(VersionStrategy::DIFF->value);
    switch_setting_to($setting, false);
    switch_setting_to($setting, VersionStrategy::DIFF->value);
    $sets = switch_sets_of($document)->count();

    app()->call([new PurgeVersionHistoryJob(TranslatedDocument::TABLE, $setting->name), 'handle']);

    expect($sets)->toBeGreaterThan(0)
        ->and(switch_sets_of($document))->toHaveCount($sets);
});

/**
 * Versioning of the table switched off, its purge run, then switched back on.
 */
function switch_off_and_on(string $table): void
{
    $setting = switch_setting(VersionStrategy::DIFF->value, $table);
    switch_setting_to($setting, false);
    app()->call([new PurgeVersionHistoryJob($table, $setting->name), 'handle']);
    switch_setting_to($setting, VersionStrategy::DIFF->value);
}

/**
 * @return list<array{type: VersionChangeType, strategy: VersionStrategy, path: string, key: mixed, contents: array<string, mixed>|null}>
 */
function switch_rows_of(VersionSet $set): array
{
    return $set->versions()->reorder('sequence')->get()
        ->map(static fn (Version $row): array => [
            'type' => $row->change_type,
            'strategy' => $row->version_strategy,
            'path' => $row->relation_path ?? 'root',
            'key' => $row->subject_key,
            'contents' => $row->contents,
        ])
        ->all();
}

it('writes a baseline with the root and every locale at the first save after versioning is back on', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $document->setTranslation('it', ['title' => 'Titolo', 'body' => 'Testo']);
    $document->save();
    $document->update(['code' => 'before']);
    switch_off_and_on(TranslatedDocument::TABLE);

    expect(switch_sets_of($document))->toHaveCount(0);

    $document->update(['code' => 'after']);

    $set = switch_sets_of($document)->sole();
    $rows = switch_rows_of($set);

    expect($set->kind)->toBe(VersionSetKind::Baseline)
        ->and(array_column($rows, 'path'))->toBe(['root', 'root', 'translations', 'translations'])
        ->and($rows[1])->toMatchArray(['type' => VersionChangeType::Updated, 'strategy' => VersionStrategy::SNAPSHOT, 'key' => null])
        ->and($rows[1]['contents'])->toMatchArray(['code' => 'after'])
        ->and(collect($rows)->firstWhere('key', ['locale' => 'it'])['contents'])->toBe(['title' => 'Titolo', 'body' => 'Testo']);
});

it('reverts to the baseline written after versioning is back on, translations in place', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $document->setTranslation('it', ['title' => 'Titolo', 'body' => 'Testo']);
    $document->save();
    switch_off_and_on(TranslatedDocument::TABLE);
    $document->update(['code' => 'anchored']);
    $baseline = switch_sets_of($document)->sole();
    $anchor = $baseline->versions()->whereNull('relation_path')->where('version_strategy', VersionStrategy::SNAPSHOT->value)->sole();
    $document->update(['code' => 'later']);

    $document->revertToVersion($anchor->getKey());

    $document->refresh();

    expect($document->code)->toBe('anchored')
        ->and($document->translations()->where('locale', 'it')->sole()->only(['title', 'body']))->toBe(['title' => 'Titolo', 'body' => 'Testo']);
});

it('writes a baseline with every link at the first save after versioning is back on, and restores to it', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $untouched = VersionedRelationRoot::query()->create(['title' => 'Untouched']);
    $subjects = collect(['A', 'B'])->map(fn (string $name): VersionedRelationSubject => VersionedRelationSubject::query()->create(['name' => $name]));
    $subjects->each(fn (VersionedRelationSubject $subject, int $index) => $root->categories()->attach($subject->getKey(), ['position' => $index + 1]));
    switch_off_and_on(VersionedRelationRoot::TABLE);

    $root->update(['title' => 'Second']);

    $baseline = switch_sets_of($root)->sole();

    expect($baseline->kind)->toBe(VersionSetKind::Baseline)
        ->and(array_column(switch_rows_of($baseline), 'path'))->toBe(['root', 'root', 'categories', 'categories'])
        ->and(switch_sets_of($untouched))->toHaveCount(0)
        ->and($untouched->currentRevision())->toBeNull();

    $root->update(['title' => 'Third']);
    $root->restoreToRevision($baseline->getKey(), (int) $root->currentRevision());
    $root->refresh();

    expect($root->title)->toBe('Second')
        ->and($root->categories()->orderBy('position')->pluck('name')->all())->toBe(['A', 'B']);
});
