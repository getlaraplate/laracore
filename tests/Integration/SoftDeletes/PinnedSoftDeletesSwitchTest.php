<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\HelpersCache;
use Modules\Core\Jobs\PurgeTrashedRowsJob;
use Modules\Core\Models\Setting;
use Modules\Core\SoftDeletes\SoftDeletesSettingConfirmation;
use Modules\Core\Tests\Stubs\SoftDeletes\PinnedSoftDeletesStubModel;

/*
 * A model that pins soft deletes on in code keeps using them whatever its setting says: switching the setting off
 * neither warns about nor empties its bin.
 */

beforeEach(function (): void {
    Schema::create(PinnedSoftDeletesStubModel::TABLE, static function (Blueprint $table): void {
        $table->id();
        $table->timestamp('deleted_at')->nullable();
        $table->boolean('is_deleted')->default(false);
    });
    DB::table(PinnedSoftDeletesStubModel::TABLE)->insert([['deleted_at' => now(), 'is_deleted' => true]]);
    HelpersCache::setModels('active', [...models(), PinnedSoftDeletesStubModel::class]);
});

afterEach(function (): void {
    HelpersCache::clearModels();
    Schema::dropIfExists(PinnedSoftDeletesStubModel::TABLE);
});

it('keeps the bin of a model that pins soft deletes on in code, and does not warn about it', function (): void {
    $name = 'soft_deletes.enabled.' . PinnedSoftDeletesStubModel::TABLE;
    $setting = Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => $name, 'type' => 'boolean', 'value' => false, 'group_name' => 'soft_deletes']);
    $on = clone $setting;
    $on->value = true;

    app()->call([new PurgeTrashedRowsJob(PinnedSoftDeletesStubModel::TABLE, $name), 'handle']);

    expect(DB::table(PinnedSoftDeletesStubModel::TABLE)->count())->toBe(1)
        ->and(app(SoftDeletesSettingConfirmation::class)->warn($on, false))->toBeNull();
});
