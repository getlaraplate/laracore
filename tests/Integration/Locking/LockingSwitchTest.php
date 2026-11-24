<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Helpers\HelpersCache;
use Modules\Core\Jobs\ReleaseLocksJob;
use Modules\Core\Locking\LockingSettingConfirmation;
use Modules\Core\Models\Setting;
use Modules\Core\Tests\Stubs\Cache\RecordingLabelIndex;
use Modules\Core\Tests\Stubs\Locking\LockableTestModel;

/*
 * Switching a table's record locking off, or removing it, deletes nothing: it releases every lock of the table, after a
 * confirmation that says how many records are locked. Optimistic locking holds no lock and asks nothing.
 */

beforeEach(function (): void {
    foreach (['lockable_test_models', 'lockable_other_models'] as $name) {
        Schema::dropIfExists($name);
        Schema::create($name, static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedBigInteger('locked_user_id')->nullable();
            $table->timestamp('locked_until')->nullable();
        });
    }

    DB::table('lockable_test_models')->insert([
        ['name' => 'free', 'locked_at' => null, 'locked_user_id' => null, 'locked_until' => null],
        ['name' => 'locked', 'locked_at' => now(), 'locked_user_id' => 7, 'locked_until' => null],
        ['name' => 'locked until', 'locked_at' => now(), 'locked_user_id' => 8, 'locked_until' => now()->addHour()],
    ]);
    DB::table('lockable_other_models')->insert([['name' => 'elsewhere', 'locked_at' => now(), 'locked_user_id' => 7, 'locked_until' => null]]);
    HelpersCache::setModels('active', [...models(), LockableTestModel::class]);
});

afterEach(function (): void {
    HelpersCache::clearModels();
    Schema::dropIfExists('lockable_test_models');
    Schema::dropIfExists('lockable_other_models');
});

function lockingSwitchSetting(mixed $value, string $name = 'locking.enabled.lockable_test_models'): Setting
{
    return Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => $name,
        'type' => 'boolean',
        'value' => $value,
        'choices' => [true, false],
        'group_name' => 'locking',
    ]);
}

function lockingSwitchTo(Setting $setting, mixed $value): void
{
    $setting->setSkipValidation(true);
    $setting->setForcedApprovalUpdate(true);
    $setting->value = $value;
    $setting->save();
}

/**
 * The names of the rows of a table that still carry a lock.
 *
 * @return list<string>
 */
function lockingSwitchLocked(string $table): array
{
    return DB::table($table)->whereNotNull('locked_at')->orderBy('id')->pluck('name')->all();
}

it('tells how many records are locked, only when record locking goes from on to off', function (): void {
    $confirmation = app(LockingSettingConfirmation::class);
    $on = lockingSwitchSetting(true);

    expect($confirmation->warn($on, false)?->lines[0])->toContain('2 locked records of lockable_test_models')
        ->and($confirmation->warn($on, true))->toBeNull()
        ->and($confirmation->warn(lockingSwitchSetting(false, 'locking.enabled.lockable_other_models'), false))->toBeNull()
        ->and($confirmation->supports('locking.optimistic.lockable_test_models'))->toBeFalse();
});

it('queues the release when a save switches record locking off, and only then', function (): void {
    Bus::fake();
    $setting = lockingSwitchSetting(true);
    $optimistic = lockingSwitchSetting(true, 'locking.optimistic.lockable_test_models');

    lockingSwitchTo($setting, true);
    lockingSwitchTo($optimistic, false);
    Bus::assertNotDispatched(ReleaseLocksJob::class);

    lockingSwitchTo($setting, false);
    lockingSwitchTo($setting, true);

    Bus::assertDispatchedTimes(ReleaseLocksJob::class, 1);
    Bus::assertDispatched(ReleaseLocksJob::class, static fn (ReleaseLocksJob $job): bool => $job->table === 'lockable_test_models');
});

it('releases only the locks of the table when the job runs with locking still off, and flushes the table', function (): void {
    lockingSwitchSetting(false);
    $index = new RecordingLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);

    app()->call([new ReleaseLocksJob('lockable_test_models', 'locking.enabled.lockable_test_models'), 'handle']);

    expect(lockingSwitchLocked('lockable_test_models'))->toBe([])
        ->and(DB::table('lockable_test_models')->count())->toBe(3)
        ->and(DB::table('lockable_test_models')->whereNotNull('locked_user_id')->exists())->toBeFalse()
        ->and(lockingSwitchLocked('lockable_other_models'))->toBe(['elsewhere'])
        ->and($index->flushedLabels())->toContain(CacheLabel::table('lockable_test_models'), CacheLabel::rows('lockable_test_models'));
});

it('keeps the locks when record locking is back on by the time the job runs', function (): void {
    Bus::fake();
    $setting = lockingSwitchSetting(true);
    lockingSwitchTo($setting, false);
    lockingSwitchTo($setting, true);

    app()->call([new ReleaseLocksJob('lockable_test_models', $setting->name), 'handle']);

    expect(lockingSwitchLocked('lockable_test_models'))->toBe(['locked', 'locked until']);
});

/**
 * Runs `model:locked-remove` on the lockable stub, its migration written to a filesystem the test controls.
 */
function lockingSwitchRemove(Filesystem $files): Illuminate\Testing\PendingCommand
{
    app()->instance(Filesystem::class, $files);

    return test()->artisan('model:locked-remove', ['model' => 'LockableTestModel', '--namespace' => 'Modules\\Core\\Tests\\Stubs\\Locking']);
}

it('changes nothing when the unlock asked by model:locked-remove is refused', function (): void {
    $setting = lockingSwitchSetting(true);
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldNotReceive('put');

    lockingSwitchRemove($files)
        ->expectsOutputToContain('Found 2 locked records on `lockable_test_models`.')
        ->expectsConfirmation('Unlock those records before removing the lock columns?', 'no')
        ->assertFailed()
        ->run();

    expect(lockingSwitchLocked('lockable_test_models'))->toBe(['locked', 'locked until'])
        ->and(Setting::query()->whereKey($setting->getKey())->exists())->toBeTrue();
});

it('unlocks the records of the table before model:locked-remove removes the columns', function (): void {
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('put')->once()->andReturn(1);
    $files->shouldReceive('makeDirectory')->andReturn(true);

    lockingSwitchRemove($files)
        ->expectsConfirmation('Unlock those records before removing the lock columns?', 'yes')
        ->expectsOutputToContain('Unlocked 2 record(s).')
        ->assertSuccessful()
        ->run();

    expect(lockingSwitchLocked('lockable_test_models'))->toBe([])
        ->and(lockingSwitchLocked('lockable_other_models'))->toBe(['elsewhere']);
});

it('releases nothing more when the job runs again, so a retry is safe', function (): void {
    lockingSwitchSetting(false);
    $job = new ReleaseLocksJob('lockable_test_models', 'locking.enabled.lockable_test_models');

    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    expect(lockingSwitchLocked('lockable_test_models'))->toBe([])
        ->and(DB::table('lockable_test_models')->count())->toBe(3)
        ->and($job->tries)->toBeGreaterThan(1);
});
