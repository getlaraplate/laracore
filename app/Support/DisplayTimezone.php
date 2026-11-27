<?php

declare(strict_types=1);

namespace Modules\Core\Support;

use DateTimeZone;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Models\User;

/**
 * The timezone a person reads dates in.
 *
 * Dates are stored and exchanged in UTC; this only decides how they are shown. The timezone is the IANA
 * identifier saved on the user, for example `Europe/Rome`. Without a user, or when the saved value is not an
 * IANA identifier, the answer is UTC.
 */
final class DisplayTimezone
{
    public const string FALLBACK = 'UTC';

    /**
     * The IANA identifiers, as keys, built once per process.
     *
     * @var array<string, true>|null
     */
    private static ?array $identifiers = null;

    /**
     * The timezone for the user signed in on the current request.
     */
    public static function current(): string
    {
        $user = Auth::user();

        return self::forUser($user instanceof User ? $user : null);
    }

    public static function forUser(?User $user): string
    {
        $timezone = $user?->timezone;

        return self::isValid($timezone) ? $timezone : self::FALLBACK;
    }

    /**
     * The identifiers a person may choose from, each as value and label.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $identifiers = DateTimeZone::listIdentifiers();

        return array_combine($identifiers, $identifiers);
    }

    /**
     * Whether the value is an IANA identifier. Offsets such as `+02:00` and abbreviations such as `CET` are
     * not: they ignore daylight saving time.
     *
     * @phpstan-assert-if-true non-empty-string $timezone
     */
    public static function isValid(?string $timezone): bool
    {
        if ($timezone === null || $timezone === '') {
            return false;
        }

        self::$identifiers ??= array_fill_keys(DateTimeZone::listIdentifiers(), true);

        return isset(self::$identifiers[$timezone]);
    }
}
