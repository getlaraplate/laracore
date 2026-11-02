<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\Tests\Stubs\Search\TranslatedSearchableStubModel;
use Modules\Core\Tests\Stubs\Search\TranslatedSearchableStubModelTranslation;

/**
 * Regression for the Task 11 review finding: prepareDataToEmbedByLocale()
 * must not rely on HasTranslations::getTranslation()'s default fallback
 * behavior. translation_fallback_enabled defaults to true — the seeded
 * app-wide default that real translated models (e.g. Content) run under,
 * with no per-model override — so a naive getTranslation($loc) call
 * silently resolves every available locale with no translation of its own
 * to the SAME default-locale translation, producing one (mislabeled and
 * duplicated) embedding entry per available locale instead of one per
 * locale the model was actually translated into.
 */
beforeEach(function (): void {
    app(PerModelSettingResolver::class)->flush();

    Schema::create('translated_searchable_stub_models', function ($table): void {
        $table->id();
    });

    Schema::create('translated_searchable_stub_model_translations', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('translated_searchable_stub_model_id');
        $table->string('locale', 10);
        $table->text('title')->nullable();
    });
});

it('returns exactly one locale entry when only the default-locale translation exists, under default fallback = true', function (): void {
    $model = new TranslatedSearchableStubModel();
    $model->saveQuietly();

    // Sanity check: this test is only meaningful under the DEFAULT config
    // (fallback enabled) — the same config Content runs under.
    expect($model->translationFallbackEnabledBySettings())->toBeTrue()
        ->and(config('app.locale'))->toBe('en')
        ->and(LocaleContext::getAvailable())->toContain('en')
        ->and(count(LocaleContext::getAvailable()))->toBeGreaterThan(1);

    TranslatedSearchableStubModelTranslation::query()->create([
        'translated_searchable_stub_model_id' => $model->id,
        'locale' => 'en',
        'title' => 'English only title',
    ]);

    $result = $model->prepareDataToEmbedByLocale();

    expect($result)->toBe(['en' => 'English only title']);
});

it('declares no embedding context header unless the model opts in', function (): void {
    $model = new TranslatedSearchableStubModel();

    expect($model->embedHeader())->toBeNull()
        ->and($model->embedHeader('en'))->toBeNull();
});
