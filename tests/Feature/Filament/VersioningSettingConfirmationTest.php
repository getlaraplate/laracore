<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Filament\Resources\Settings\Pages\EditSetting;
use Modules\Core\Jobs\PurgeVersionHistoryJob;
use Modules\Core\Models\Role;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;
use Modules\Core\Models\Version;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocument;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocumentTranslation;
use Modules\Core\Versioning\VersionHistoryPurger;

uses(RefreshDatabase::class);

/*
 * Switching a table's versioning off on the settings page asks first, naming the history it deletes and what it keeps
 * (decision D22), then saves `false` and queues the purge.
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

    if (! class_exists(App\Models\User::class)) {
        class_alias(User::class, App\Models\User::class);
    }

    /** @var App\Models\User $actor */
    $actor = App\Models\User::query()->create(User::factory()->raw());
    $actor->assignRole(Role::findOrCreate(config('permission.roles.superadmin'), 'web'));
    $this->actingAs($actor);
    Filament::setCurrentPanel('admin');
});

afterEach(function (): void {
    Schema::dropIfExists(TranslatedDocumentTranslation::TABLE);
    Schema::dropIfExists(TranslatedDocument::TABLE);
});

function versioning_switch_setting(): Setting
{
    return Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'versioning.strategy.' . TranslatedDocument::TABLE,
        'type' => 'json',
        'value' => VersionStrategy::DIFF->value,
        'choices' => [false, ...VersionStrategy::cases()],
        'group_name' => 'versioning',
    ]);
}

it('asks before switching versioning off, naming the history it deletes, then saves false and queues the purge', function (): void {
    Bus::fake();
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $document->code = 'changed';
    $document->save();
    ['sets' => $sets, 'rows' => $rows] = app(VersionHistoryPurger::class)->countFor(TranslatedDocument::TABLE);
    $setting = versioning_switch_setting();

    Livewire::test(EditSetting::class, ['record' => $setting->getKey()])
        ->assertFormSet(['value' => VersionStrategy::DIFF->value])
        ->fillForm(['value' => 'false'])
        ->call('save')
        ->assertActionMounted('confirmSettingChange')
        ->assertMountedActionModalSee([
            'Switch versioning off?',
            "{$sets} revisions, {$rows} version rows",
            'The snapshots of records deleted for good are kept',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($sets)->toBeGreaterThan(0)
        ->and($setting->fresh()->value)->toBeFalse();
    Bus::assertDispatched(PurgeVersionHistoryJob::class, static fn (PurgeVersionHistoryJob $job): bool => $job->table === TranslatedDocument::TABLE);
});

it('shows a switched-off setting as false and switches it back on without asking', function (): void {
    Bus::fake();
    $setting = versioning_switch_setting();
    $setting->setSkipValidation(true);
    $setting->setForcedApprovalUpdate(true);
    $setting->value = false;
    $setting->save();

    Livewire::test(EditSetting::class, ['record' => $setting->getKey()])
        ->assertFormSet(['value' => 'false'])
        ->fillForm(['value' => VersionStrategy::SNAPSHOT->value])
        ->call('save')
        ->assertActionNotMounted('confirmSettingChange')
        ->assertHasNoFormErrors();

    expect($setting->fresh()->value)->toBe(VersionStrategy::SNAPSHOT->value);
    Bus::assertDispatchedTimes(PurgeVersionHistoryJob::class, 1);
});
