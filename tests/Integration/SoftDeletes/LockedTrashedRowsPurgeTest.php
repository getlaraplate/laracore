<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\HelpersCache;
use Modules\Core\Jobs\PurgeTrashedRowsJob;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;
use Modules\Core\SoftDeletes\TrashedRowsPurger;
use Modules\Core\Tests\Stubs\SoftDeletes\LockedSoftDeletesStubModel;

/*
 * A row in the bin can still carry a lock (its holder deleted it, and nothing clears the lock columns). The purge is a
 * maintenance write run by nobody who holds the lock: it lifts the lock guard, as the other maintenance writes do.
 */

beforeEach(function (): void {
    config()->set('core.locking.prevent_modifications', true);
    Schema::create(LockedSoftDeletesStubModel::TABLE, static function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->boolean('is_deleted')->default(false);
        $table->timestamp('locked_at')->nullable();
        $table->unsignedBigInteger('locked_user_id')->nullable();
        $table->timestamp('locked_until')->nullable();
    });
    DB::table(LockedSoftDeletesStubModel::TABLE)->insert([
        ['name' => 'live', 'deleted_at' => null, 'is_deleted' => false, 'locked_at' => null, 'locked_user_id' => null],
        ['name' => 'trashed and locked', 'deleted_at' => now(), 'is_deleted' => true, 'locked_at' => now(), 'locked_user_id' => User::factory()->create()->id],
    ]);
    HelpersCache::setModels('active', [...models(), LockedSoftDeletesStubModel::class]);
});

afterEach(function (): void {
    HelpersCache::clearModels();
    Schema::dropIfExists(LockedSoftDeletesStubModel::TABLE);
});

/**
 * The names of the rows left in the table.
 *
 * @return list<string>
 */
function lockedTrashedRowsLeft(): array
{
    return DB::table(LockedSoftDeletesStubModel::TABLE)->orderBy('id')->pluck('name')->all();
}

it('purges a row in the bin locked by another user through the purger', function (): void {
    expect(app(TrashedRowsPurger::class)->purge(new LockedSoftDeletesStubModel()))->toBe(1)
        ->and(lockedTrashedRowsLeft())->toBe(['live']);
});

it('purges a row in the bin locked by another user through the job', function (): void {
    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'soft_deletes.enabled.' . LockedSoftDeletesStubModel::TABLE,
        'type' => 'boolean',
        'value' => false,
        'group_name' => 'soft_deletes',
    ]);

    app()->call([new PurgeTrashedRowsJob(LockedSoftDeletesStubModel::TABLE, 'soft_deletes.enabled.' . LockedSoftDeletesStubModel::TABLE), 'handle']);

    expect(lockedTrashedRowsLeft())->toBe(['live']);
});

it('purges a row in the bin locked by another user through model:soft-deletes-remove', function (): void {
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('put')->andReturn(1);
    app()->instance(Filesystem::class, $files);

    $this->artisan('model:soft-deletes-remove', ['model' => 'LockedSoftDeletesStubModel', '--namespace' => 'Modules\\Core\\Tests\\Stubs\\SoftDeletes'])
        ->expectsConfirmation('Purge those records with hard delete before removing soft delete columns?', 'yes')
        ->assertSuccessful()
        ->run();

    expect(lockedTrashedRowsLeft())->toBe(['live']);
});
