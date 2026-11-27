<?php

declare(strict_types=1);

namespace Modules\Core\Events;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;

/**
 * Event emitted when a model requires automatic translation.
 * This event is separate from ModelRequiresIndexing but can be
 * synchronized with indexing if the model is searchable.
 */
class TranslatedModelSaved
{
    public bool $handled = false;

    public function __construct(
        public readonly Model $model,
        public readonly array $locales = [],
        public readonly bool $force = false,
        /**
         * Set at emission when the save is part of a revert (D36): the translations were put back as they were, and
         * translating them again would overwrite what the revert restored.
         */
        public readonly bool $fromRevert = false,
    ) {}

    /**
     * The event of a save happening now: `fromRevert` is read from the version set in progress at emission, not when a
     * listener runs, so a queued listener gets the same answer (D36).
     *
     * @param  list<string>  $locales
     */
    public static function ofCurrentSave(Model $model, array $locales = [], bool $force = false): self
    {
        return new self($model, $locales, $force, resolve(VersionSetManagerInterface::class)->current()?->isRevert() === true);
    }

    public function markAsHandled(): void
    {
        $this->handled = true;
    }

    public function isHandled(): bool
    {
        return $this->handled;
    }
}
