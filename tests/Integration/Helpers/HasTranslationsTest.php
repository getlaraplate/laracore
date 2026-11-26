<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Overrides\LocaleScope;
use Modules\Core\Tests\Fixtures\FakeTranslatableModel;
use Modules\Core\Tests\Fixtures\FakeTranslatableModelTranslation;
use Modules\Core\Tests\Stubs\Database\Db2PretendConnection;

beforeEach(function (): void {
    HasTranslations::resetLocaleCache();

    Schema::create('fake_translatable_models', function (Blueprint $table): void {
        $table->id();
        $table->string('title')->nullable();
        $table->string('slug')->nullable();
        $table->json('components')->nullable();
        $table->timestamps();
    });

    Schema::create('fake_translatable_model_translations', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('fake_translatable_model_id')->constrained('fake_translatable_models')->cascadeOnDelete();
        $table->string('locale');
        $table->string('title')->nullable();
        $table->string('slug')->nullable();
        $table->json('components')->nullable();
        $table->timestamps();
    });
});

afterEach(function (): void {
    HasTranslations::resetLocaleCache();
});

// ---------------------------------------------------------------------------
// resetLocaleCache
// ---------------------------------------------------------------------------

it('exposes resetLocaleCache static method', function (): void {
    expect(method_exists(HasTranslations::class, 'resetLocaleCache'))->toBeTrue();
});

it('resetLocaleCache sets the static cache back to null', function (): void {
    // Manually populate the cache via reflection
    $reflection = new ReflectionProperty(LocaleContext::class, 'cached_default_locale');
    $reflection->setValue(null, 'it');

    expect($reflection->getValue())->toBe('it');

    HasTranslations::resetLocaleCache();

    expect($reflection->getValue())->toBeNull();
});

// ---------------------------------------------------------------------------
// Property 5: HasTranslations avoids extra queries when translations collection is loaded
// Validates: Requirements 4.1, 4.2
// ---------------------------------------------------------------------------

/**
 * Property 5: When the `translations` collection is already loaded in memory,
 * getTranslatableFieldValue() must NOT issue a new DB query to retrieve the fallback translation.
 *
 * Setup: the `translation` HasOne is unset (simulating a miss), but the `translations`
 * collection is already loaded. The fallback path should use the in-memory collection.
 */
it('does not issue a DB query when translations collection is already loaded (Property 5)', function (): void {
    // Feature: performance-optimization, Property 5: HasTranslations avoids extra queries when translations collection is loaded
    HasTranslations::resetLocaleCache();

    $current_locale = config('app.locale');

    // Create model with a translation for the current locale
    $model = FakeTranslatableModel::query()->create([]);
    $model->setTranslation($current_locale, ['title' => 'Default Title']);
    $model->save();

    // Reload the model bypassing LocaleScope, eager-loading the full `translations` collection
    $fresh = FakeTranslatableModel::query()
        ->withoutGlobalScope(LocaleScope::class)
        ->with('translations')
        ->find($model->id);

    expect($fresh)->not->toBeNull();

    // Unset the `translation` HasOne to force the fallback path
    $fresh->unsetRelation('translation');

    // Count queries before and after — no new query should be issued
    $fresh->getConnection()->enableQueryLog();
    $count_before = count($fresh->getConnection()->getQueryLog());

    $title = $fresh->title;

    $count_after = count($fresh->getConnection()->getQueryLog());

    expect($title)->toBe('Default Title')
        ->and($count_after)->toBe($count_before);
});

/**
 * Property 5 — property-based variant.
 *
 * For any model instance where the `translations` collection is already loaded,
 * getTranslatableFieldValue() SHALL NOT issue a new DB query to retrieve the default translation.
 *
 * Validates: Requirements 4.1, 4.2
 */
it('never queries DB for fallback when translations collection is loaded (property test)', function (): void {
    // Feature: performance-optimization, Property 5: HasTranslations avoids extra queries when translations collection is loaded
    HasTranslations::resetLocaleCache();

    $current_locale = config('app.locale');
    $title_value = fake()->sentence(3);

    $model = FakeTranslatableModel::query()->create([]);
    $model->setTranslation($current_locale, ['title' => $title_value]);
    $model->save();

    // Reload with the full translations collection, bypassing LocaleScope
    $fresh = FakeTranslatableModel::query()
        ->withoutGlobalScope(LocaleScope::class)
        ->with('translations')
        ->find($model->id);

    expect($fresh)->not->toBeNull();

    $fresh->unsetRelation('translation');

    $fresh->getConnection()->enableQueryLog();
    $count_before = count($fresh->getConnection()->getQueryLog());

    $result = $fresh->title;

    $count_after = count($fresh->getConnection()->getQueryLog());

    expect($result)->toBe($title_value)
        ->and($count_after)->toBe($count_before);
})->repeat(10);

it('returns null without querying DB when translations collection is loaded but locale is missing', function (): void {
    // Feature: performance-optimization, Property 5: HasTranslations avoids extra queries when translations collection is loaded
    HasTranslations::resetLocaleCache();

    // Create model with NO translation for the current locale
    $model = FakeTranslatableModel::query()->create([]);
    // Do not add any translation — the collection will be empty

    // Reload with the full translations collection, bypassing LocaleScope
    $fresh = FakeTranslatableModel::query()
        ->withoutGlobalScope(LocaleScope::class)
        ->with('translations')
        ->find($model->id);

    expect($fresh)->not->toBeNull();

    $fresh->unsetRelation('translation');

    $fresh->getConnection()->enableQueryLog();
    $count_before = count($fresh->getConnection()->getQueryLog());

    $result = $fresh->title;

    $count_after = count($fresh->getConnection()->getQueryLog());

    // No translation exists, but no query should be issued either
    expect($result)->toBeNull()
        ->and($count_after)->toBe($count_before);
});

// ---------------------------------------------------------------------------
// Property 18: Default locale is read from config at most once per request
// Validates: Requirements 13.1
// ---------------------------------------------------------------------------

it('populates the static default locale cache after first getTranslatableFieldValue call', function (): void {
    // Feature: performance-optimization, Property 18: Default locale is read from config at most once per request
    HasTranslations::resetLocaleCache();

    $current_locale = config('app.locale');

    $model = FakeTranslatableModel::query()->create([]);
    $model->setTranslation($current_locale, ['title' => 'Titolo']);
    $model->save();

    // Reload with translations collection to avoid DB query in fallback
    $fresh = FakeTranslatableModel::query()
        ->withoutGlobalScope(LocaleScope::class)
        ->with('translations')
        ->find($model->id);

    expect($fresh)->not->toBeNull();
    $fresh->unsetRelation('translation');

    // Access the field — this should populate the static cache
    $fresh->title;

    $reflection = new ReflectionProperty(LocaleContext::class, 'cached_default_locale');
    $cached_value = $reflection->getValue();

    // The cache must be populated (non-null) after the first access
    expect($cached_value)->not->toBeNull();
});

/**
 * Property 18 — property-based variant.
 *
 * For any number of getTranslatableFieldValue() calls within the same request,
 * config('app.locale') SHALL be invoked at most once.
 *
 * Validates: Requirements 13.1
 */
it('reads config app.locale at most once for N calls to getTranslatableFieldValue (property test)', function (): void {
    // Feature: performance-optimization, Property 18: Default locale is read from config at most once per request
    HasTranslations::resetLocaleCache();

    $current_locale = config('app.locale');

    $model = FakeTranslatableModel::query()->create([]);
    $model->setTranslation($current_locale, ['title' => fake()->sentence(2)]);
    $model->save();

    // Reload with translations collection to avoid DB queries in fallback
    $fresh = FakeTranslatableModel::query()
        ->withoutGlobalScope(LocaleScope::class)
        ->with('translations')
        ->find($model->id);

    expect($fresh)->not->toBeNull();
    $fresh->unsetRelation('translation');

    $n = fake()->numberBetween(2, 20);

    for ($i = 0; $i < $n; $i++) {
        $fresh->title;
    }

    // After N calls the static cache must be populated (non-null)
    $reflection = new ReflectionProperty(LocaleContext::class, 'cached_default_locale');
    $cached_value = $reflection->getValue();

    expect($cached_value)->not->toBeNull();
})->repeat(10);

it('re-reads config after resetLocaleCache is called', function (): void {
    // Feature: performance-optimization, Property 18: Default locale is read from config at most once per request
    $reflection = new ReflectionProperty(LocaleContext::class, 'cached_default_locale');

    // Manually set the cache to a known value
    $reflection->setValue(null, 'it');
    expect($reflection->getValue())->toBe('it');

    // Reset clears it
    HasTranslations::resetLocaleCache();
    expect($reflection->getValue())->toBeNull();

    // After reset, the next call to getTranslatableFieldValue will re-populate it
    $current_locale = config('app.locale');
    $model = FakeTranslatableModel::query()->create([]);
    $model->setTranslation($current_locale, ['title' => 'Title']);
    $model->save();

    $fresh = FakeTranslatableModel::query()
        ->withoutGlobalScope(LocaleScope::class)
        ->with('translations')
        ->find($model->id);

    expect($fresh)->not->toBeNull();
    $fresh->unsetRelation('translation');
    $fresh->title;

    expect($reflection->getValue())->not->toBeNull();
});

it('returns the earliest translation as the original', function (): void {
    $model = FakeTranslatableModel::query()->create([]);
    $model->setTranslation('it', ['title' => 'Originale']);
    $this->travel(1)->minutes();
    $model->setTranslation('en', ['title' => 'Translated']);

    expect($model->fresh()->getOriginalTranslation()?->getAttribute('locale'))->toBe('it');
});

it('returns no original translation for a model without translations', function (): void {
    expect(FakeTranslatableModel::query()->create([])->getOriginalTranslation())->toBeNull();
});

it('quotes the reserved locale column in every translation order it builds on Db2', function (): void {
    // Warm the fallback setting on SQLite: the pretend connection answers no query.
    expect((new FakeTranslatableModel)->translationFallbackEnabledBySettings())->toBeTrue();
    $orders = [];

    Db2PretendConnection::capture(static function () use (&$orders): void {
        $model = (new FakeTranslatableModel)->setConnection('db2_pretend');
        $order_of = static function (Closure $loader) use ($model): string {
            $relation = $model->hasOne(FakeTranslatableModelTranslation::class);
            $loader($relation);

            return $relation->toSql();
        };

        LocaleContext::set('it');
        $orders[] = $model->translation()->toSql();

        LocaleContext::setContentLocale('it');
        $orders[] = $model->translation()->toSql();
        LocaleContext::setContentLocale(null);

        foreach (['forLocale', 'withTranslation'] as $scope) {
            $builder = $model->newQueryWithoutScopes();
            $model->callNamedScope($scope, [$builder, 'it', true]);
            $orders[] = $order_of($builder->getEagerLoads()['translation']);
        }
    });

    expect($orders)->toHaveCount(4)
        ->and($orders[0])->toContain('order by CASE WHEN "LOCALE" = ? THEN 0 ELSE 1 END')
        ->and($orders[1])->toContain('order by CASE WHEN "LOCALE" = ? THEN 0 WHEN "LOCALE" = ? THEN 1 ELSE 2 END')
        ->and($orders[2])->toContain('order by CASE WHEN "LOCALE" = ? THEN 0 ELSE 1 END')
        ->and($orders[3])->toContain('order by CASE WHEN "LOCALE" = ? THEN 0 ELSE 1 END');
});
