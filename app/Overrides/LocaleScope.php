<?php

declare(strict_types=1);

namespace Modules\Core\Overrides;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Core\Helpers\LocaleContext;

final class LocaleScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $default_locale = LocaleContext::getDefault();
        $current_locale = LocaleContext::get();
        $fallback_enabled = $this->modelUsesTranslationFallback($model);

        $use_fallback = $fallback_enabled && $current_locale !== $default_locale;

        // A reader who picked the content locale (the backoffice) sees every record, translated or not, so a
        // missing translation can be found and written.
        if (LocaleContext::getContentLocale() === null) {
            $builder->whereHas('translations', function (Builder $query) use ($current_locale, $default_locale, $use_fallback): void {
                $this->applyLocaleConstraint($query, $current_locale, $default_locale, $use_fallback);
            });
        }

        // Eager load traduzione corrente (con fallback se abilitato)
        // The backoffice lists every record, so where the content locale has no translation it shows the default
        // one, whatever the model's fallback, and any other one where the default is missing too: an empty cell
        // would read as missing data. Forms do not use this relation for what they write (they ask for the
        // translation without fallback).
        $this->eagerLoadTranslation($builder, $current_locale, $default_locale, $fallback_enabled, LocaleContext::getContentLocale() !== null);
    }

    /**
     * Extend the query builder with the needed functions.
     *
     * @param  Builder<Model>  $builder
     */
    public function extend(Builder $builder): void
    {
        $builder->macro('forLocale', function (Builder $builder, ?string $locale = null, ?bool $with_fallback = null): Builder {
            $locale ??= LocaleContext::get();
            $default_locale = LocaleContext::getDefault();
            $fallback_enabled = $with_fallback ?? $this->modelUsesTranslationFallback($builder->getModel());

            // Rimuovi il global scope
            $builder->withoutGlobalScope($this);

            // Applica filtro per lingua specifica
            if ($fallback_enabled && $locale !== $default_locale) {
                // Mostra contenuti con traduzione richiesta O di default
                $builder->whereHas('translations', function (Builder $query) use ($locale, $default_locale): void {
                    $query->where('locale', $locale)
                        ->orWhere('locale', $default_locale);
                });
            } else {
                // Mostra SOLO contenuti con traduzione richiesta
                $builder->whereHas('translations', function (Builder $query) use ($locale): void {
                    $query->where('locale', $locale);
                });
            }

            // Carica traduzione
            /** @var \Illuminate\Database\Query\Builder $query */
            $builder->with(['translation' => function ($query) use ($locale, $default_locale, $fallback_enabled): void { // @pest-ignore-type
                if ($fallback_enabled) {
                    $query->where(function ($q) use ($locale, $default_locale): void { // @pest-ignore-type
                        $q->where('locale', $locale)
                            ->orWhere('locale', $default_locale);
                    })
                        ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$locale]);
                } else {
                    $query->where('locale', $locale);
                }
            }]);

            return $builder;
        });
    }

    /**
     * Eager load translation with fallback logic.
     *
     * @param  Builder<Model>  $builder
     */
    private function eagerLoadTranslation(
        Builder $builder,
        string $current_locale,
        string $default_locale,
        bool $fallback_enabled,
        bool $any_locale = false,
    ): void {
        /** @var \Illuminate\Database\Query\Builder $query */
        $builder->with(['translation' => function ($query) use ($current_locale, $default_locale, $fallback_enabled, $any_locale): void { // @pest-ignore-type
            if ($any_locale) {
                // Carica traduzione corrente, poi quella di default, poi una qualsiasi
                $query->orderByRaw('CASE WHEN locale = ? THEN 0 WHEN locale = ? THEN 1 ELSE 2 END', [$current_locale, $default_locale])
                    ->orderBy('id');
            } elseif ($fallback_enabled) {
                // Carica traduzione corrente, se non esiste usa quella di default
                /** @var \Illuminate\Database\Query\Builder $q */
                $query->where(function ($q) use ($current_locale, $default_locale): void { // @pest-ignore-type
                    $q->where('locale', $current_locale)
                        ->orWhere('locale', $default_locale);
                })
                    ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$current_locale]);
            } else {
                // Carica SOLO traduzione corrente (null se non esiste)
                $query->where('locale', $current_locale);
            }
        }]);
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyLocaleConstraint(Builder $query, string $current_locale, string $default_locale, bool $use_fallback): void
    {
        if (! $use_fallback) {
            $target_locale = $current_locale;
            $query->where('locale', $target_locale);

            return;
        }

        $query->where('locale', $current_locale)
            ->orWhere('locale', $default_locale);
    }

    private function modelUsesTranslationFallback(Model $model): bool
    {
        if (method_exists($model, 'translationFallbackEnabledBySettings')) {
            return $model->translationFallbackEnabledBySettings();
        }

        return true;
    }
}
