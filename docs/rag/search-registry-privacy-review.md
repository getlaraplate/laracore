# Search registry: privacy review

Decision record for the application search registry, the daily aggregate of what guests and readers search (`core_search_query_stats`). Status: Pending owner approval. The registry stays off until approved. The registry is built on the field set below and nothing else; a change to any item needs a new review.

## Purpose

To see which searches find little or nothing, so the content and the synonyms can be improved. It is a count of queries, never a record of people.

## Field set

One row per normalized query, locale, searched model and day (unique on those four), in the table `core_search_query_stats`:

| Field | Content |
|---|---|
| `query` | the normalized query: lowercased, whitespace collapsed, request words removed, at most 255 characters |
| `locale` | locale of the request |
| `model` | table of the searched model |
| `day` | the calendar day (`Y-m-d`), no time |
| `searches` | number of searches of that day |
| `low_result_searches` | how many of them returned fewer results than the threshold |
| `result_sum` | sum of the results returned |
| `created_at` | time of the first recorded search of that query that day |
| `updated_at` | time of the latest recorded search of that query that day |

## Never stored

- Any user id, IP address, session id, user agent or request header: no column holds them, and the recording job receives none.
- A query that contains an email, a UUID, a structured identifier or a number: it is discarded before anything is written, not masked.
- An empty query, or one longer than 255 characters.
- Result content, document ids, filters.
- A per-search time. The only times kept are the `created_at` and `updated_at` of each daily row (first and latest recorded search of that query that day); no other per-search time is stored.

## Who is recorded and why

Guests and readers, because the purpose is to learn what the public looks for. Editors (holders of the update permission of the searched model) are not recorded: their searches are about maintaining content, not about demand. Failed searches (a result with an error) are not recorded either. The criterion is the person, not the route.

Recording is queued after the response and can never fail or slow a search: an error in it is reported and swallowed.

## Settings, not environment variables

Seeded settings, edited in Filament:

| Setting | Default |
|---|---|
| `search.registry.enabled` | `false` |
| `search.registry.retention_days` | `90` |
| `search.registry.low_results_threshold` | `5` |

They are read as `config('core.search.registry.*')`.

## Retention

`search:prune-registry` runs daily (`->daily()->onOneServer()`) and deletes the rows whose `day` is older than today minus `retention_days`. It runs also while the registry is off, so rows recorded earlier still expire. A retention of 0 or less keeps everything.

## Erasure

No per-person erasure is needed: no person is stored, so there is nothing to look up or delete for one. The remaining risk is a query that holds personal data not recognized as an email, a UUID, an identifier or a number (for example a name typed as a search). It is bounded by the retention window and by the fact that a row cannot be tied to a person.
