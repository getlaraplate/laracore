<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Utils;

use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;

/**
 * Fills a toggle from the record it edits when the model hides the attribute.
 *
 * Filament fills an edit form from `attributesToArray()`, which leaves the `$hidden` attributes out, and models
 * hide flags such as `is_active` to keep them out of serialized output (see {@see \Modules\Core\Models\Concerns\HasActivation}).
 * Such a toggle received no value: it showed switched off whatever the record held and, saved untouched,
 * wrote false. Only booleans are filled this way, because the other hidden attributes (password hashes,
 * tokens, secrets) must stay out of the form.
 */
final class HiddenToggleHydrator
{
    public function register(): void
    {
        Toggle::configureUsing(function (Toggle $toggle): void {
            $toggle->afterStateHydrated(fn (Toggle $component): null => $this->hydrate($component));
        });
    }

    public function hydrate(Toggle $toggle): null
    {
        $record = $toggle->getRecord();

        if (! $record instanceof Model || ! $record->exists) {
            return null;
        }

        $name = $toggle->getName();

        if (in_array($name, $record->getHidden(), true)) {
            $toggle->state((bool) $record->getAttribute($name));
        }

        return null;
    }
}
