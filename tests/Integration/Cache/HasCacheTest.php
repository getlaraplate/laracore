<?php

declare(strict_types=1);

use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Models\Setting;
use Modules\Core\Tests\Stubs\Cache\RecordingLabelIndex;

it('exposes cache key and usage flag', function (): void {
    $setting = new Setting();

    expect($setting->usesCache())->toBeTrue()
        ->and($setting->getCacheKey())->toBe($setting->getTable());
});

it('invalidates cache when a cached model is saved or deleted', function (): void {
    $setting = Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'cache_save_' . uniqid(),
        'value' => 'initial',
        'type' => SettingTypeEnum::String,
        'group_name' => 'cache_group',
        'description' => 'test',
    ]);

    $setting->value = 'updated';
    $setting->save();

    $setting->delete();

    expect($setting->usesCache())->toBeTrue();
});

it('invalidates the label of its table', function (): void {
    $index = new RecordingLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);

    (new Setting())->invalidateCache();

    expect($index->flushedLabels())->toBe([CacheLabel::table((new Setting())->getTable())]);
});
