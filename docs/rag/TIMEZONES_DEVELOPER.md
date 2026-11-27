# Dates and timezones: developer guide

## The rule

Dates are stored and exchanged in UTC. The timezone of a reader decides only how a date is shown.

| Where | Timezone |
|---|---|
| Database, `app.timezone` | UTC, fixed. `APP_TIMEZONE` does not exist. |
| Database session | UTC: `timezone` is `+00:00` on `mysql` and `mariadb`, `UTC` on `pgsql`, in `config/database.php`. |
| API responses | ISO 8601 in UTC with a `Z` suffix, for example `2025-03-30T02:45:37.000000Z`. Eloquent serialization already does this. |
| What a person reads | The timezone saved on the user, else UTC. |

Why the database session is UTC too: a `TIMESTAMP` column reads the string it is given in the session
timezone. In a server timezone with daylight saving time, a UTC instant that falls in the skipped hour, for
example `2025-03-30 02:45:37` in Europe/Rome, is refused with error 1292. Rows written before the session
was fixed to UTC were read in the server timezone: an installation keeping its data re-creates or corrects
it, a fresh installation is not affected.

## The pieces

- `users.timezone`: nullable IANA identifier, in the user migration. Set from the profile page, from
  `PATCH /app/auth/user/preferences` (see `USER_PREFERENCES_DEVELOPER.md`) or by the browser script below.
- `Modules\Core\Support\DisplayTimezone`: `current()` for the signed in user, `forUser()`, `isValid()` and
  `options()`. An unset or non-IANA value gives `UTC`.
- Filament: `CoreServiceProvider::configureFilamentDefaults()` calls
  `FilamentTimezone::set(fn () => DisplayTimezone::current())`. Every date-time column, entry and picker
  that sets no timezone of its own uses it. Date-only fields keep `app.timezone`, so a birthday does not move.
- `Modules\Core\Filament\Pages\EditProfile`: the profile page, with language and timezone. Registered with
  `->profile(EditProfile::class)` in `AdminPanelProvider`.
- `Modules\Core\Filament\Plugins\BrowserTimezonePlugin`: renders a script for a signed in user with no
  timezone. It sends the browser timezone once per session to the preferences endpoint. The browser reports
  some zones by an old alias that PHP does not list: the endpoint then answers 422 and the user picks one in
  the profile.
- `Modules\Core\Support\DateFormatter`: the one way a date leaves the application outside Filament.
  `wire()` for machines, `display()` and `displayWithZone()` for people, `html()` for a `<time>` element.

## Writing dates

- A date typed in a Filament date-time field is read in the user's timezone and stored in UTC.
- A date sent to the API is an instant in ISO 8601. A value with an offset is converted, a value without
  one is read as UTC. A date-only value such as a birthday is not an instant and is never converted.
- Format a date with `DateFormatter`, never with `->format()` in a mail, a notification or an export. Where
  the reader may not have a saved timezone, use `displayWithZone()` so the hour carries its abbreviation.

## Tests

`tests/Feature/Support/UtcContractTest.php` pins the UTC configuration and the `Z` serialization.
`tests/Feature/Support/DateFormatterTest.php`, `tests/Feature/Filament/DisplayTimezoneTest.php`,
`tests/Feature/Filament/EditProfilePageTest.php` and `tests/Feature/Filament/BrowserTimezonePluginTest.php`
cover the rest. SQLite has no daylight saving gap: the 1292 error itself can only be reproduced on MySQL.
