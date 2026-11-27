<?php

declare(strict_types=1);

namespace Modules\Core\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contract for Eloquent models that use the HasTranslations trait.
 */
interface ITranslatableModel
{
    /**
     * @return list<string>
     */
    public static function getTranslatableFields(): array;

    public function getOriginalTranslation(): ?Model;

    public function getTranslation(?string $locale = null, ?bool $with_fallback = null): ?Model;

    /**
     * @param  array<string, mixed>  $data
     */
    public function setTranslation(string $locale, array $data): static;

    public function hasTranslation(?string $locale = null): bool;

    public function autoTranslateEnabledBySettings(): bool;

    /**
     * @return HasMany<covariant Model, covariant Model>
     */
    public function translations(): HasMany;
}
