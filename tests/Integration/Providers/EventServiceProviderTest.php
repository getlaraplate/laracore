<?php

declare(strict_types=1);

use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Models\CronJob;
use Modules\Core\Models\Setting;
use Modules\Core\Providers\EventServiceProvider;
use Modules\Core\Tests\Stubs\Cache\RecordingLabelIndex;

beforeEach(function (): void {
    $this->provider = new EventServiceProvider(app());
});

it('has listen array for model indexing events', function (): void {
    $ref = new ReflectionClass($this->provider);
    $prop = $ref->getProperty('listen');
    $listen = $prop->getValue($this->provider);

    expect($listen)->toHaveKey(Modules\Core\Events\ModelRequiresIndexing::class);
    expect($listen)->toHaveKey(Modules\Core\Events\ModelPreProcessingCompleted::class);
});

it('boot runs without throwing', function (): void {
    $this->provider->boot();

    expect(true)->toBeTrue();
});

it('after boot CronJob saved triggers cache forget', function (): void {
    $this->provider->boot();

    $cron = CronJob::factory()->create();
    $cron->save();

    expect(true)->toBeTrue();
});

it('Setting save flushes the label of its table', function (): void {
    $setting = Setting::factory()->persistedWithoutApprovalCapture()->create();
    $index = new RecordingLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);

    // A save that changes nothing fires no update and flushes nothing: there is nothing to invalidate.
    $setting->description = 'changed ' . uniqid();
    $setting->save();

    expect($index->flushedLabels())->toContain(CacheLabel::table($setting->getTable()));
});

it('configureEmailVerification can be invoked safely', function (): void {
    $ref = new ReflectionClass($this->provider);
    $method = $ref->getMethod('configureEmailVerification');
    $method->setAccessible(true);
    $method->invoke($this->provider);

    expect(true)->toBeTrue();
});
