<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Jobs\PurgeVersionHistoryJob;
use Modules\Core\Models\Setting;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationRoot;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocument;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocumentTranslation;
use Modules\Core\Versioning\VersionHistoryPurger;

/**
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
});

afterEach(function (): void {
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
function switch_setting(mixed $value): Setting
{
    return Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'versioning.strategy.' . TranslatedDocument::TABLE,
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
