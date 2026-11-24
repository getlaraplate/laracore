<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Utils;

use Modules\Core\Filament\FilamentTraitResolver;

/**
 * Strips computed/appended attributes from Filament form state before Livewire hydration.
 *
 * @phpstan-require-extends \Filament\Resources\Pages\Page
 */
trait HasFilamentFormDataSanitizer
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->stripUnsupportedFilamentFormAttributes(parent::mutateFormDataBeforeFill($data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->stripUnsupportedFilamentFormAttributes(parent::mutateFormDataBeforeCreate($data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->stripUnsupportedFilamentFormAttributes(parent::mutateFormDataBeforeSave($data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stripUnsupportedFilamentFormAttributes(array $data): array
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
