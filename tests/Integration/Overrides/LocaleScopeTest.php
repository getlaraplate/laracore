<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\Setting;
use Modules\Core\Overrides\LocaleScope;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\Tests\Fixtures\FakeTranslatableModel;

function configureFakeTranslatableFallback(bool $enabled): void
{
    Setting::query()->where('name', 'translations.locale_fallback.fake_translatable_models')->forceDelete();

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'translations.locale_fallback.fake_translatable_models',
        'value' => $enabled,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'translations',
        'description' => 'test',
    ]);

    app(PerModelSettingResolver::class)->flush();
}

beforeEach(function (): void {
    app(PerModelSettingResolver::class)->flush();
    Setting::query()->where('group_name', 'translations')->delete();

    $this->scope = new LocaleScope();

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

it('extend adds forLocale macro to builder', function (): void {
    $model = new FakeTranslatableModel();
    $builder = $model->newQuery();

    $this->scope->extend($builder);

    expect($builder->getMacro('forLocale'))->not->toBeNull();
});

it('apply runs without throwing when locale equals default', function (): void {
    config(['app.locale' => 'en']);
    LocaleContext::set('en');

    $model = new FakeTranslatableModel();
    $builder = $model->newQuery();

    $this->scope->apply($builder, $model);

    expect(true)->toBeTrue();
});

it('apply filters with fallback when locale differs from default and fallback enabled', function (): void {
    config(['app.locale' => 'en']);
    configureFakeTranslatableFallback(true);
    LocaleContext::set('it');

    $model = new FakeTranslatableModel();
    $builder = $model->newQuery();

    $this->scope->apply($builder, $model);

    $sql = $builder->toSql();
    expect($sql)->toContain('translations');
});

it('apply filters only current locale when fallback disabled and locale differs', function (): void {
    config(['app.locale' => 'en']);
    configureFakeTranslatableFallback(false);
    LocaleContext::set('it');

    $model = new FakeTranslatableModel();
    $builder = $model->newQuery();

    $this->scope->apply($builder, $model);

    $sql = $builder->toSql();
    expect($sql)->toContain('translations');
});

it('forLocale macro filters with fallback for non-default locale', function (): void {
    config(['app.locale' => 'en']);
    configureFakeTranslatableFallback(true);
    LocaleContext::set('en');

    $model = new FakeTranslatableModel();
    $builder = $model->newQuery();

    $this->scope->extend($builder);

    $result = $builder->forLocale('it', true);
    expect($result->toSql())->toContain('translations');
});

it('forLocale macro filters without fallback', function (): void {
    config(['app.locale' => 'en']);
    LocaleContext::set('en');

    $model = new FakeTranslatableModel();
    $builder = $model->newQuery();

    $this->scope->extend($builder);

    $result = $builder->forLocale('it', false);
    expect($result->toSql())->toContain('translations');
});

it('forLocale with fallback enabled and locale equals default filters only locale', function (): void {
    config(['app.locale' => 'en']);
    LocaleContext::set('en');

    $model = new FakeTranslatableModel();
    $builder = $model->newQuery();

    $this->scope->extend($builder);

    $result = $builder->forLocale('en', true);
    expect($result->toSql())->toContain('translations');
});

it('apply uses only current locale branch when fallback is disabled', function (): void {
    config(['app.locale' => 'en']);
    configureFakeTranslatableFallback(false);
    LocaleContext::set('it');

    $relation_query = Mockery::mock(Illuminate\Database\Eloquent\Builder::class);
    $relation_query->shouldReceive('where')->atLeast()->once()->with('locale', 'it');

    $builder = Mockery::mock(Illuminate\Database\Eloquent\Builder::class);
    $builder->shouldReceive('whereHas')->once()->with('translations', Mockery::on(function (callable $callback) use ($relation_query): bool {
        $callback($relation_query);

        return true;
    }))->andReturnSelf();
    $builder->shouldReceive('with')->once()->with(Mockery::on(function (array $with) use ($relation_query): bool {
        $closure = $with['translation'] ?? null;

        if (! $closure instanceof Closure) {
            return false;
        }
        $closure($relation_query);

        return true;
    }))->andReturnSelf();

    $model = new FakeTranslatableModel();
    $this->scope->apply($builder, $model);

    expect(true)->toBeTrue();
});

it('forLocale without fallback constrains translation locale in eager load', function (): void {
    $model = new FakeTranslatableModel();
    $builder = $model->newQuery();
    $this->scope->extend($builder);
    $query = Mockery::mock(Illuminate\Database\Query\Builder::class);
    $query->shouldReceive('where')->once()->with('locale', 'it');
    $builder->with([
        'translation' => function ($q): void {
            //
        },
    ]);

    $result = $builder->forLocale('it', false);
    $eager_loads = $result->getEagerLoads();
    $translation_loader = $eager_loads['translation'];
    $translation_loader($query);

    expect(true)->toBeTrue();
});

it('apply executes whereHas closure for fallback when query runs against database', function (): void {
    config(['app.locale' => 'en']);
    configureFakeTranslatableFallback(true);
    LocaleContext::set('it');

    $model = new FakeTranslatableModel();
    $builder = $model->newQuery();
    $this->scope->apply($builder, $model);

    expect(fn () => $builder->exists())->not->toThrow(Throwable::class);
});

it('apply executes whereHas closure for current locale only when fallback disabled and query runs', function (): void {
    config(['app.locale' => 'en']);
    configureFakeTranslatableFallback(false);
    LocaleContext::set('it');

    $model = new FakeTranslatableModel();
    $builder = $model->newQuery();
    $this->scope->apply($builder, $model);

    expect(fn () => $builder->exists())->not->toThrow(Throwable::class);
});

it('apply invokes whereHas fallback closure with current and default locale constraints', function (): void {
    config(['app.locale' => 'en']);
    configureFakeTranslatableFallback(true);
    LocaleContext::set('it');

    $parent_model = new FakeTranslatableModel();
    $relation_for_where_has = $parent_model->translations()->getQuery();
    $relation_for_eager = (new FakeTranslatableModel())->translations()->getQuery();

    $builder = Mockery::mock(Illuminate\Database\Eloquent\Builder::class);
    $builder->shouldReceive('whereHas')->once()->with('translations', Mockery::on(function (callable $callback) use ($relation_for_where_has): bool {
        $callback($relation_for_where_has);

        return true;
    }))->andReturnSelf();
    $builder->shouldReceive('with')->once()->with(Mockery::on(function (array $with) use ($relation_for_eager): bool {
        $closure = $with['translation'] ?? null;

        if (! $closure instanceof Closure) {
            return false;
        }
        $closure($relation_for_eager);

        return true;
    }))->andReturnSelf();

    $this->scope->apply($builder, $parent_model);

    expect($relation_for_where_has->toSql())->toContain('locale')
        ->and($relation_for_eager->toSql())->toContain('locale');
});

it('apply invokes whereHas closure for current locale only when fallback is disabled', function (): void {
    config(['app.locale' => 'en']);
    configureFakeTranslatableFallback(false);
    LocaleContext::set('it');

    $parent_model = new FakeTranslatableModel();
    $relation_for_where_has = $parent_model->translations()->getQuery();
    $relation_for_eager = (new FakeTranslatableModel())->translations()->getQuery();

    $builder = Mockery::mock(Illuminate\Database\Eloquent\Builder::class);
    $builder->shouldReceive('whereHas')->once()->with('translations', Mockery::on(function (callable $callback) use ($relation_for_where_has): bool {
        $callback($relation_for_where_has);

        return true;
    }))->andReturnSelf();
    $builder->shouldReceive('with')->once()->with(Mockery::on(function (array $with) use ($relation_for_eager): bool {
        $closure = $with['translation'] ?? null;

        if (! $closure instanceof Closure) {
            return false;
        }
        $closure($relation_for_eager);

        return true;
    }))->andReturnSelf();

    $this->scope->apply($builder, $parent_model);

    expect($relation_for_where_has->toSql())->toContain('locale')
        ->and($relation_for_eager->toSql())->toContain('locale');
});

it('apply invokes whereHas closure for default locale branch', function (): void {
    config(['app.locale' => 'en']);
    configureFakeTranslatableFallback(true);
    LocaleContext::set('en');

    $nested = Mockery::mock(Illuminate\Database\Query\Builder::class);
    $nested->shouldReceive('where')->once()->with('locale', 'en')->andReturnSelf();
    $nested->shouldReceive('orWhere')->once()->with('locale', 'en')->andReturnSelf();

    $relation_query = Mockery::mock(Illuminate\Database\Eloquent\Builder::class);
    $relation_query->shouldReceive('where')->once()->with('locale', 'en')->andReturnSelf();
    $relation_query->shouldReceive('where')->once()->with(Mockery::on(function ($arg) use ($nested): bool {
        if (! $arg instanceof Closure) {
            return false;
        }
        $arg($nested);

        return true;
    }))->andReturnSelf();
    $relation_query->shouldReceive('orderByRaw')->once()->andReturnSelf();

    $builder = Mockery::mock(Illuminate\Database\Eloquent\Builder::class);
    $builder->shouldReceive('whereHas')->once()->with('translations', Mockery::on(function (callable $callback) use ($relation_query): bool {
        $callback($relation_query);

        return true;
    }))->andReturnSelf();
    $builder->shouldReceive('with')->once()->with(Mockery::on(function (array $with) use ($relation_query): bool {
        $closure = $with['translation'] ?? null;

        if (! $closure instanceof Closure) {
            return false;
        }
        $closure($relation_query);

        return true;
    }))->andReturnSelf();

    $this->scope->apply($builder, new FakeTranslatableModel());

    expect(true)->toBeTrue();
});

it('apply returns only default locale records when current locale equals default', function (): void {
    config(['app.locale' => 'en']);
    configureFakeTranslatableFallback(true);
    LocaleContext::set('en');

    $with_en = FakeTranslatableModel::query()->create([]);
    $with_it_only = FakeTranslatableModel::query()->create([]);

    $with_en->setTranslation('en', ['title' => 'Hello'])->save();
    $with_it_only->setTranslation('it', ['title' => 'Ciao'])->save();

    $ids = FakeTranslatableModel::query()->pluck('id')->all();

    expect($ids)->toContain($with_en->id)
        ->and($ids)->not->toContain($with_it_only->id);
});

it('apply fallback returns rows with current or default locale', function (): void {
    config(['app.locale' => 'en']);
    configureFakeTranslatableFallback(true);
    LocaleContext::set('it');

    $with_en = FakeTranslatableModel::query()->create([]);
    $with_it = FakeTranslatableModel::query()->create([]);
    $with_de = FakeTranslatableModel::query()->create([]);

    $with_en->setTranslation('en', ['title' => 'Hello'])->save();
    $with_it->setTranslation('it', ['title' => 'Ciao'])->save();
    $with_de->setTranslation('de', ['title' => 'Hallo'])->save();

    $ids = FakeTranslatableModel::query()->pluck('id')->all();

    expect($ids)->toContain($with_it->id)
        ->and($ids)->not->toContain($with_de->id);
});

it('apply without fallback returns only current locale rows', function (): void {
    config(['app.locale' => 'en']);
    configureFakeTranslatableFallback(false);
    LocaleContext::set('it');

    $with_en = FakeTranslatableModel::query()->create([]);
    $with_it = FakeTranslatableModel::query()->create([]);

    $with_en->setTranslation('en', ['title' => 'Hello'])->save();
    $with_it->setTranslation('it', ['title' => 'Ciao'])->save();

    $ids = FakeTranslatableModel::query()->pluck('id')->all();

    expect($ids)->toContain($with_it->id)
        ->and($ids)->not->toContain($with_en->id);
});

it('forLocale fallback eager-load orders current locale first', function (): void {
    config(['app.locale' => 'en']);
    configureFakeTranslatableFallback(true);

    $model = new FakeTranslatableModel();
    $builder = $model->newQuery();
    $this->scope->extend($builder);

    $result = $builder->forLocale('it', true);
    $eager_loads = $result->getEagerLoads();
    $translation_loader = $eager_loads['translation'];

    $nested = Mockery::mock(Illuminate\Database\Query\Builder::class);
    $nested->shouldReceive('where')->once()->with('locale', 'it')->andReturnSelf();
    $nested->shouldReceive('orWhere')->once()->with('locale', 'en')->andReturnSelf();

    $query = Mockery::mock(Illuminate\Database\Query\Builder::class);
    $query->shouldReceive('where')->once()->with(Mockery::on(function ($arg) use ($nested): bool {
        if (! $arg instanceof Closure) {
            return false;
        }
        $arg($nested);

        return true;
    }))->andReturnSelf();
    $query->shouldReceive('orderByRaw')->once()->with('CASE WHEN locale = ? THEN 0 ELSE 1 END', ['it'])->andReturnSelf();

    $translation_loader($query);

    expect(true)->toBeTrue();
});

it('applyLocaleConstraint uses fallback branch with where and orWhere', function (): void {
    $query = Mockery::mock(Illuminate\Database\Eloquent\Builder::class);
    $query->shouldReceive('where')->once()->with('locale', 'it')->andReturnSelf();
    $query->shouldReceive('orWhere')->once()->with('locale', 'en')->andReturnSelf();

    $method = new ReflectionMethod(LocaleScope::class, 'applyLocaleConstraint');
    $method->setAccessible(true);
    $method->invoke($this->scope, $query, 'it', 'en', true);

    expect(true)->toBeTrue();
});

it('applyLocaleConstraint uses single where when fallback is disabled', function (): void {
    $query = Mockery::mock(Illuminate\Database\Eloquent\Builder::class);
    $query->shouldReceive('where')->once()->with('locale', 'it')->andReturnSelf();

    $method = new ReflectionMethod(LocaleScope::class, 'applyLocaleConstraint');
    $method->setAccessible(true);
    $method->invoke($this->scope, $query, 'it', 'en', false);

    expect(true)->toBeTrue();
});

it('defaults translation fallback to enabled when model has no setting hook', function (): void {
    $method = new ReflectionMethod(LocaleScope::class, 'modelUsesTranslationFallback');
    $method->setAccessible(true);

    $model = new class extends Illuminate\Database\Eloquent\Model {};

    expect($method->invoke($this->scope, $model))->toBeTrue();
});

/**
 * A record translated only in the default locale. Inserted below the model so no translation hook runs.
 */
function fakeTranslatedOnlyInDefaultLocale(string $title): int
{
    $id = (new FakeTranslatableModel)->newQueryWithoutScopes()->insertGetId(['created_at' => now(), 'updated_at' => now()]);

    Modules\Core\Tests\Fixtures\FakeTranslatableModelTranslation::query()->create([
        'fake_translatable_model_id' => $id,
        'locale' => LocaleContext::getDefault(),
        'title' => $title,
    ]);

    return $id;
}

it('falls back to the default locale after the request switches language', function (): void {
    configureFakeTranslatableFallback(true);
    $id = fakeTranslatedOnlyInDefaultLocale('Only in the default locale');

    LocaleContext::set('de');

    $record = FakeTranslatableModel::query()->find($id);

    expect($record)->not->toBeNull()
        ->and($record->title)->toBe('Only in the default locale')
        ->and($record->getTranslation()?->title)->toBe('Only in the default locale');
});

it('hides a record with no translation in the language of the request when the fallback is off', function (): void {
    configureFakeTranslatableFallback(false);
    $id = fakeTranslatedOnlyInDefaultLocale('Only in the default locale');

    LocaleContext::set('de');

    expect(FakeTranslatableModel::query()->find($id))->toBeNull();
});

it('keeps every record visible when the reader picked a content locale, showing the default text where it is missing', function (): void {
    configureFakeTranslatableFallback(false);
    $untranslated = fakeTranslatedOnlyInDefaultLocale('Only in the default locale');
    $translated = fakeTranslatedOnlyInDefaultLocale('Default text');
    Modules\Core\Tests\Fixtures\FakeTranslatableModelTranslation::query()->create([
        'fake_translatable_model_id' => $translated,
        'locale' => 'de',
        'title' => 'Deutscher Text',
    ]);

    LocaleContext::setContentLocale('de');

    $records = FakeTranslatableModel::query()->get()->keyBy('id');

    expect($records->keys()->all())->toEqualCanonicalizing([$untranslated, $translated])
        ->and($records[$translated]->title)->toBe('Deutscher Text')
        ->and($records[$untranslated]->title)->toBe('Only in the default locale');
});

it('falls back to the locale the default language setting names, once the setting is saved', function (): void {
    configureFakeTranslatableFallback(true);
    config(['app.available_locales' => ['en', 'it', 'de']]);
    $id = (new FakeTranslatableModel)->newQueryWithoutScopes()->insertGetId(['created_at' => now(), 'updated_at' => now()]);
    Modules\Core\Tests\Fixtures\FakeTranslatableModelTranslation::query()->create([
        'fake_translatable_model_id' => $id,
        'locale' => 'it',
        'title' => 'Solo italiano',
    ]);

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'default_language',
        'module' => 'Core',
        'value' => 'it',
        'type' => SettingTypeEnum::String,
        'group_name' => 'core',
        'description' => 'test',
    ]);
    LocaleContext::set('de');

    expect(FakeTranslatableModel::query()->find($id)?->title)->toBe('Solo italiano');
});

it('shows the text of any locale in the backoffice when neither the content locale nor the default one has it', function (): void {
    configureFakeTranslatableFallback(false);
    $other_locale = LocaleContext::getDefault() === 'fr' ? 'es' : 'fr';
    $id = (new FakeTranslatableModel)->newQueryWithoutScopes()->insertGetId(['created_at' => now(), 'updated_at' => now()]);
    Modules\Core\Tests\Fixtures\FakeTranslatableModelTranslation::query()->create([
        'fake_translatable_model_id' => $id,
        'locale' => $other_locale,
        'title' => 'Only in another locale',
    ]);

    LocaleContext::setContentLocale('de');

    $record = FakeTranslatableModel::query()->find($id);

    expect($record)->not->toBeNull()
        ->and($record->title)->toBe('Only in another locale')
        ->and(FakeTranslatableModel::query()->get()->firstWhere('id', $id)?->title)->toBe('Only in another locale');
});

it('prefers the default locale over any other in the backoffice when the content locale is missing', function (): void {
    configureFakeTranslatableFallback(false);
    $other_locale = LocaleContext::getDefault() === 'fr' ? 'es' : 'fr';
    $id = fakeTranslatedOnlyInDefaultLocale('Default text');
    Modules\Core\Tests\Fixtures\FakeTranslatableModelTranslation::query()->create([
        'fake_translatable_model_id' => $id,
        'locale' => $other_locale,
        'title' => 'Text in another locale',
    ]);

    LocaleContext::setContentLocale('de');

    expect(FakeTranslatableModel::query()->get()->firstWhere('id', $id)?->title)->toBe('Default text');
});

it('still hides a record translated only in another locale outside the backoffice', function (): void {
    configureFakeTranslatableFallback(true);
    $other_locale = LocaleContext::getDefault() === 'fr' ? 'es' : 'fr';
    $id = (new FakeTranslatableModel)->newQueryWithoutScopes()->insertGetId(['created_at' => now(), 'updated_at' => now()]);
    Modules\Core\Tests\Fixtures\FakeTranslatableModelTranslation::query()->create([
        'fake_translatable_model_id' => $id,
        'locale' => $other_locale,
        'title' => 'Only in another locale',
    ]);

    LocaleContext::set('de');

    expect(FakeTranslatableModel::query()->find($id))->toBeNull();
});
