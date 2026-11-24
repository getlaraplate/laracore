<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Utils;

use Filament\Schemas\Components\StateCasts\Contracts\StateCast;
use Filament\Schemas\Components\StateCasts\OptionStateCast;
use Override;

/**
 * The state cast of a select over the choices of a JSON setting, where a boolean is the option keyed `true` or
 * `false`. Filament's option cast runs before any `formatStateUsing()` and turns `false` into an empty string, which
 * reads as no selection; this cast maps the boolean to its key first and leaves every other value to the option cast.
 * The select turns the key back into the boolean when it dehydrates.
 */
final readonly class JsonChoiceStateCast implements StateCast
{
    private OptionStateCast $optionCast;

    public function __construct()
    {
        $this->optionCast = new OptionStateCast(isNullable: true);
    }

    #[Override]
    public function get(mixed $state): mixed
    {
        return $this->optionCast->get(self::booleanAsKey($state));
    }

    #[Override]
    public function set(mixed $state): mixed
    {
        return $this->optionCast->set(self::booleanAsKey($state));
    }

    private static function booleanAsKey(mixed $state): mixed
    {
        return is_bool($state) ? ($state ? 'true' : 'false') : $state;
    }
}
