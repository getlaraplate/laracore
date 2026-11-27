<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Modules\Core\Actions\Docs\MergeSwaggerDocsAction;
use Modules\Core\Actions\Settings\GetSiteConfigsAction;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Http\Controllers\DocsController;
use Modules\Core\Http\Controllers\SettingController;
use Modules\Core\Models\Setting;
use Modules\Core\Tests\Stubs\Cache\SpyLabelIndex;

beforeEach(function (): void {
    $this->index = new SpyLabelIndex();
    app()->instance(ICacheLabelIndex::class, $this->index);
});

it('serves the merged docs from the index on the second read and recomputes them after the docs label is flushed', function (): void {
    $computed = 0;
    app()->instance(MergeSwaggerDocsAction::class, new MergeSwaggerDocsAction(
        new Filesystem(),
        function () use (&$computed): array {
            $computed++;

            return [];
        },
    ));
    config(['swagger-ui.files' => [
        'docs' => ['path' => '/docs/api', 'versions' => ['v1' => 'v1.json'], 'title' => 'Docs'],
    ]]);

    $controller = app(DocsController::class);
    $request = Illuminate\Http\Request::create('/docs/api/v1', 'GET');
    $read = static function () use ($controller, $request): void {
        try {
            $controller->mergeDocs($request, 'v1');
        } catch (Throwable) {
            // Only the cached merge is under test, not the rendering of the file around it.
        }
    };

    $read();
    $read();

    expect($computed)->toBe(1)
        ->and($this->index->stores)->toHaveCount(1)
        ->and($this->index->stores[0]['lookup']->dependencies->labels)->toBe(['docs'])
        ->and($this->index->stores[0]['ttl'])->toBe((int) config('cache.duration.long'));

    $this->index->flush('docs');
    $read();

    expect($computed)->toBe(2);
});

it('serves the site configs from the index on the second read and recomputes them after a setting is saved', function (): void {
    $computed = 0;
    app()->instance(GetSiteConfigsAction::class, new GetSiteConfigsAction(
        function () use (&$computed): array {
            $computed++;

            return [];
        },
        static fn (): array => [],
    ));
    Route::get('/test-cached/site-configs', [SettingController::class, 'getSiteConfigs'])->name('test.cached.site-configs');

    $this->getJson('/test-cached/site-configs')->assertOk();
    $this->getJson('/test-cached/site-configs')->assertOk();

    expect($computed)->toBe(1)
        ->and($this->index->stores[0]['lookup']->dependencies->labels)->toBe(['table:core_settings'])
        ->and($this->index->stores[0]['ttl'])->toBe((int) config('cache.duration.long'));

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'cached_site_config_probe',
        'value' => 1,
        'type' => SettingTypeEnum::Integer,
        'group_name' => 'cached_probe',
        'description' => 'test',
    ]);
    $this->getJson('/test-cached/site-configs')->assertOk();

    expect($computed)->toBe(2);
});

it('serves the translations from the index on the second read and recomputes them after the translations label is flushed', function (): void {
    Route::get('/test-cached/translations/{lang?}', [SettingController::class, 'getTranslations'])->name('test.cached.translations');

    $this->getJson('/test-cached/translations/en')->assertOk();
    $this->getJson('/test-cached/translations/en')->assertOk();

    expect($this->index->stores)->toHaveCount(1)
        ->and($this->index->stores[0]['lookup']->dependencies->labels)->toBe(['translations'])
        ->and($this->index->stores[0]['ttl'])->toBe((int) config('cache.duration.long'));

    $this->index->flush('translations');
    $this->getJson('/test-cached/translations/en')->assertOk();

    expect($this->index->stores)->toHaveCount(2);
});
