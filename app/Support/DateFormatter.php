<?php

declare(strict_types=1);

namespace Modules\Core\Support;

use Carbon\CarbonInterface;

/**
 * The one way a date leaves the application.
 *
 * Machines get the wire form: ISO 8601 in UTC with a Z suffix, the one Eloquent serialization already
 * writes. People get the display form: the same instant in their own timezone and language. No surface
 * formats a date by itself.
 */
final class DateFormatter
{
    /**
     * Localized date and time, for example 30/03/2025 04:45 in Italian.
     */
    public const string DATE_TIME = 'L LT';

    /**
     * The wire form of an instant, for example 2025-03-30T02:45:37.000000Z.
     */
    public static function wire(?CarbonInterface $date): ?string
    {
        return $date?->toISOString();
    }

    /**
     * The display form of an instant.
     *
     * @param  string|null  $timezone  An IANA identifier. Omitted, the timezone of the signed in user applies.
     * @param  string|null  $locale  A language code. Omitted, the language of the request applies.
     * @param  string  $format  A Carbon `isoFormat` format.
     */
    public static function display(
        ?CarbonInterface $date,
        ?string $timezone = null,
        ?string $locale = null,
        string $format = self::DATE_TIME,
    ): ?string {
        if (! $date instanceof CarbonInterface) {
            return null;
        }

        $timezone = DisplayTimezone::isValid($timezone) ? $timezone : DisplayTimezone::current();

        return $date->copy()
            ->setTimezone($timezone)
            ->locale($locale ?? app()->getLocale())
            ->isoFormat($format);
    }

    /**
     * The display form followed by the timezone abbreviation, for readers whose timezone is not known,
     * such as the recipient of an email who has never signed in.
     */
    public static function displayWithZone(
        ?CarbonInterface $date,
        ?string $timezone = null,
        ?string $locale = null,
        string $format = self::DATE_TIME,
    ): ?string {
        return self::display($date, $timezone, $locale, $format . ' z');
    }

    /**
     * A `<time>` element holding the wire form and the display form as its text. A script may later
     * replace the text with the timezone of the browser, which a guest has no saved value for.
     */
    public static function html(
        ?CarbonInterface $date,
        ?string $timezone = null,
        ?string $locale = null,
        string $format = self::DATE_TIME,
    ): string {
        if (! $date instanceof CarbonInterface) {
            return '';
        }

        return sprintf(
            '<time datetime="%s">%s</time>',
            e(self::wire($date)),
            e(self::displayWithZone($date, $timezone, $locale, $format)),
        );
    }
}
