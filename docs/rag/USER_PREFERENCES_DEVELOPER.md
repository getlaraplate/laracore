# User preferences: developer guide

## What the store is

`users.preferences` is one JSON object per user, echoed by `GET /app/auth/user/profile-information` as
`preferences`. A client reads it from there and writes it with the routes below. The routes act on the
session user's own row, accept no target id, and bypass the CRUD `update` authorization and its versioning
events (`saveQuietly()`), which gate administrators editing other accounts.

Preferences are cosmetic and untrusted: they are never read to decide what a user may see or do.

## Namespaces

The top-level keys of the bag are namespaces. A client owns one, so two clients on the same account never
overwrite each other. A namespace matches `^[a-z][a-z0-9_.-]{0,39}$`.

| Limit | Value |
|---|---|
| Serialized size of the whole bag | 64 KiB |
| Depth of arrays, the bag being the first level | 6 |
| Values | JSON only: text (valid UTF-8), numbers (finite), booleans, null, lists and objects |
| Top-level keys | namespaces; a list or a numeric key is refused |

The limits live in code, in `Modules\Core\Rules\PreferencesBag`, not in env. A write that breaks one answers
422. The size is checked on the payload and again on the bag it merges into, so two writes cannot add up
past the limit.

## Routes

| Route | Effect |
|---|---|
| `PATCH /app/auth/user/preferences` | Body `{"preferences": {namespace: value, ...}, "lang": "it", "timezone": "Europe/Rome"}`, each key optional but at least one required. Replaces each namespace it receives, removes one sent as `null`, leaves the others. `lang` and `timezone` are the user's own columns: a value sets it, `null` clears it, a key left out is not touched. |
| `DELETE /app/auth/user/preferences` | Clears the whole bag. |
| `DELETE /app/auth/user/preferences/{namespace}` | Clears one namespace. A name that is not a namespace is a 404. |

All of them answer the refreshed profile. A request that is not signed in answers 401.

Merging per namespace replaces the former whole-bag replacement: two devices or two clients that write
different namespaces both survive. A namespace replaced is replaced as a whole, so a client sends its whole
namespace, not a patch of it. Namespaces may later register a JSON Schema in Core; until one does, only the
generic limits apply.

## Language and timezone

`lang` must be one of `app.available_locales`. `timezone` must be an IANA identifier (`timezone:all`):
offsets such as `+02:00` and abbreviations such as `CET` are refused with a 422, because they ignore daylight
saving time. Both come back in the refreshed profile, `GET /app/auth/user/profile-information`, as `lang`
and `timezone`. See `docs/rag/TIMEZONES_DEVELOPER.md` for what the timezone is used for.

## Testing

`Modules/Core/tests/Feature/Controllers/UserPreferencesTest.php`. A test that switches user between two
requests calls `flushSession()` first: `AuthenticateSession` otherwise logs the second user out.
