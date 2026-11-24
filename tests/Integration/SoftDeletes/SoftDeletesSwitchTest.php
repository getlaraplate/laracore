<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\HelpersCache;
use Modules\Core\Jobs\PurgeTrashedRowsJob;
use Modules\Core\Models\Setting;
use Modules\Core\SoftDeletes\SoftDeletesSettingConfirmation;
use Modules\Core\Tests\Stubs\SoftDeletesStubModel;

/*
 * Switching a table's soft deletes off deletes the rows in its bin for good: the settings page asks first with their
 * count, any save that switches it off queues the purge, and the purge does nothing when soft deletes are back on.
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
        ['name' => 'trashed too', 'deleted_at' => now(), 'is_deleted' => true],
    ]);
    DB::table('soft_deletes_other_stub')->insert([['deleted_at' => now()]]);
    HelpersCache::setModels('active', [...models(), SoftDeletesStubModel::class]);
});

afterEach(function (): void {
    HelpersCache::clearModels();
    Schema::dropIfExists('soft_deletes_stub');
    Schema::dropIfExists('soft_deletes_other_stub');
});

function softDeletesSwitchSetting(mixed $value, string $name = 'soft_deletes.enabled.soft_deletes_stub'): Setting
{
    return Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => $name,
        'type' => 'boolean',
        'value' => $value,
        'choices' => [true, false],
        'group_name' => 'soft_deletes',
    ]);
}

function softDeletesSwitchTo(Setting $setting, mixed $value): void
{
    $setting->setSkipValidation(true);
    $setting->setForcedApprovalUpdate(true);
    $setting->value = $value;
    $setting->save();
}

it('tells how many records in the bin are deleted for good, only when soft deletes go from on to off', function (): void {
    $confirmation = app(SoftDeletesSettingConfirmation::class);
    $on = softDeletesSwitchSetting(true);
    $off = softDeletesSwitchSetting(false, 'soft_deletes.enabled.soft_deletes_other_stub');

    $warning = $confirmation->warn($on, false);

    expect($warning?->lines[0])->toContain('2 records of soft_deletes_stub')
        ->and($confirmation->warn($on, true))->toBeNull()
        ->and($confirmation->warn($off, false))->toBeNull()
        ->and($confirmation->warn($off, true))->toBeNull()
        ->and($confirmation->supports('soft_deletes.enabled.soft_deletes_stub'))->toBeTrue()
        ->and($confirmation->supports('versioning.strategy.soft_deletes_stub'))->toBeFalse();
});

it('queues the purge when a save switches soft deletes off, and only then', function (): void {
    Bus::fake();
    $setting = softDeletesSwitchSetting(true);
    $other = Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'translations.auto.soft_deletes_stub', 'type' => 'boolean', 'value' => true, 'group_name' => 'translations']);

    softDeletesSwitchTo($setting, true);
    softDeletesSwitchTo($other, false);
    Bus::assertNotDispatched(PurgeTrashedRowsJob::class);

    softDeletesSwitchTo($setting, false);
    Bus::assertDispatched(
        PurgeTrashedRowsJob::class,
        static fn (PurgeTrashedRowsJob $job): bool => $job->table === 'soft_deletes_stub' && $job->settingName === $setting->name,
    );

    softDeletesSwitchTo($setting, true);
    Bus::assertDispatchedTimes(PurgeTrashedRowsJob::class, 1);
});

it('force deletes only the records in the bin of the table when the job runs with soft deletes still off', function (): void {
    $force_deleted = [];
    Event::listen('eloquent.forceDeleted: ' . SoftDeletesStubModel::class, static function (SoftDeletesStubModel $model) use (&$force_deleted): void {
        $force_deleted[] = $model->name;
    });
    softDeletesSwitchSetting(false);

    app()->call([new PurgeTrashedRowsJob('soft_deletes_stub', 'soft_deletes.enabled.soft_deletes_stub'), 'handle']);

    expect(DB::table('soft_deletes_stub')->pluck('name')->all())->toBe(['live'])
        ->and($force_deleted)->toEqualCanonicalizing(['trashed', 'trashed too'])
        ->and(DB::table('soft_deletes_other_stub')->count())->toBe(1);
});

it('keeps the records in the bin when soft deletes are back on by the time the job runs', function (): void {
    Bus::fake();
    $setting = softDeletesSwitchSetting(true);
    softDeletesSwitchTo($setting, false);
    softDeletesSwitchTo($setting, true);

    app()->call([new PurgeTrashedRowsJob('soft_deletes_stub', $setting->name), 'handle']);

    expect(DB::table('soft_deletes_stub')->count())->toBe(3);
});

it('warns and changes nothing when the refresh command is refused the alignment of the setting to a model without the trait', function (string $answer, bool $switched): void {
    Bus::fake();
    HelpersCache::setModels('active', [Modules\Core\Tests\Stubs\Console\MassWriteStubModel::class]);
    $setting = softDeletesSwitchSetting(true, 'soft_deletes.enabled.mass_write_stub');

    $this->artisan('model:soft-deletes-refresh')
        ->expectsOutputToContain('records of mass_write_stub in the bin are deleted for good')
        ->expectsConfirmation('Runtime setting soft_deletes.enabled.mass_write_stub=true differs from trait state (false). Align setting now?', $answer)
        ->run();

    expect((bool) $setting->fresh()->value)->toBe(! $switched);
    Bus::assertDispatchedTimes(PurgeTrashedRowsJob::class, $switched ? 1 : 0);
})->with(['refused' => ['no', false], 'confirmed' => ['yes', true]]);

it('purges nothing more when the job runs again, so a retry after a timeout resumes safely', function (): void {
    softDeletesSwitchSetting(false);
    $job = new PurgeTrashedRowsJob('soft_deletes_stub', 'soft_deletes.enabled.soft_deletes_stub');

    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    expect(DB::table('soft_deletes_stub')->pluck('name')->all())->toBe(['live'])
        ->and($job->tries)->toBeGreaterThan(1);
});

it('never runs two purges of one table at once', function (): void {
    softDeletesSwitchSetting(false);
    $job = new PurgeTrashedRowsJob('soft_deletes_stub', 'soft_deletes.enabled.soft_deletes_stub');
    $middleware = $job->middleware()[0];
    $held = Illuminate\Support\Facades\Cache::lock($middleware->getLockKey($job), 60);
    $held->get();
    $ran = false;

    $middleware->handle($job, static function () use (&$ran): void {
        $ran = true;
    });
    $held->release();

    expect($middleware)->toBeInstanceOf(Illuminate\Queue\Middleware\WithoutOverlapping::class)
        ->and($middleware->key)->toBe('soft_deletes_stub')
        ->and($middleware->expiresAfter)->toBeGreaterThan(0)
        ->and($ran)->toBeFalse()
        ->and(new PurgeTrashedRowsJob('soft_deletes_other_stub', 'x')->middleware()[0]->getLockKey($job))->not->toBe($middleware->getLockKey($job));
});
