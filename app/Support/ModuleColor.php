<?php

declare(strict_types=1);

namespace Modules\Core\Support;

use Filament\Support\Colors\Color;
use Nwidart\Modules\Facades\Module;
use Throwable;

/**
 * Single source for a module's colour.
 *
 * The colour is declared once in the module's `module.json`, as the terminal colour name
 * already used by that module's console command descriptions (`<fg=cyan>`), so the
 * backoffice and the CLI show the same identity. Filament needs a palette instead of a
 * terminal name, hence the translation below.
 */
final class ModuleColor
{
    /**
     * Terminal colour name to the closest Filament palette.
     *
     * @var array<string, string>
     */
    private const array PALETTES = [
        'black' => 'Gray',
        'blue' => 'Blue',
        'cyan' => 'Cyan',
        'green' => 'Green',
        'magenta' => 'Fuchsia',
        'red' => 'Rose',
        'white' => 'Gray',
        'yellow' => 'Yellow',
        'bright-blue' => 'Sky',
        'bright-cyan' => 'Teal',
        'bright-green' => 'Lime',
        'bright-magenta' => 'Purple',
        'bright-red' => 'Red',
        'bright-yellow' => 'Amber',
    ];

    /**
     * Terminal colour name declared by the module, as used in `<fg=...>` tags.
     */
    public static function terminal(string $module): ?string
    {
        try {
            $color = Module::findOrFail($module)->get('color');
        } catch (Throwable) {
            return null;
        }

        return is_string($color) && $color !== '' ? $color : null;
    }

    /**
     * Filament palette for the module, ready for `Panel::colors()`.
     *
     * @return array<int|string, string>|null
     */
    public static function filament(string $module): ?array
    {
        $terminal = self::terminal($module);

        if ($terminal === null) {
            return null;
        }

        $palette = self::PALETTES[$terminal] ?? null;

        if ($palette === null) {
            return null;
        }

        /** @var array<int|string, string> $color */
        return constant(Color::class . '::' . $palette);
    }
}
