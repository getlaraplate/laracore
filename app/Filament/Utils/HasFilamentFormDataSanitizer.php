<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Utils;

use Modules\Core\Filament\FilamentTraitResolver;

/**
 * Strips computed/appended attributes from Filament form state before Livewire hydration and before a write.
 *
 * @phpstan-require-extends \Filament\Resources\Pages\Page
 */
trait HasFilamentFormDataSanitizer
{
    /**
     * The form state without the computed attributes no form holds. A create page calls it on the data it
     * creates from, an edit page on the data it fills the form with and on the data it saves: each page hooks
     * only the methods its own Filament base has.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function stripUnsupportedFilamentFormAttributes(array $data): array
    {
        $model_class = static::getResource()::getModel();

        if (! is_string($model_class) || $model_class === '') {
            return $data;
        }

        foreach (FilamentTraitResolver::computedAttributesNeverInForms($model_class) as $attribute) {
            unset($data[$attribute]);
        }

        return $data;
    }
}
