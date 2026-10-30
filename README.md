<p>&nbsp;</p>
<p align="center">
	<a href="https://github.com/getlaraplate" target="_blank">
		<img src="https://raw.githubusercontent.com/getlaraplate/brand/main/logos/blueprint/core.svg" width="160" alt="LaraCore" />
    </a>
</p>
<p>&nbsp;</p>
<p align="center">
    <img src="https://img.shields.io/badge/Laravel-12.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 12">
    <img src="https://img.shields.io/badge/PHP-8.5+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2+">
    <img src="https://img.shields.io/badge/License-GNU_AGPL_v3-green?style=for-the-badge" alt="GNU AGPL v3 License">
</p>
<p>&nbsp;</p>

# Laraplate Core Module

> ⚠️ **Caution**: This package is a **work in progress**. **Don't use this in production or use at your own risk**—no guarantees are provided... or better yet, collaborate with me to create the definitive Laravel boilerplate; that's the right place to introduce your ideas. Let me know your ideas...

## Table of Contents

-   [Description](#description)
-   [Installation](#installation)
-   [Configuration](#configuration)
-   [Upgrade notes](#upgrade-notes)
-   [Features](#features)
-   [Scripts](#scripts)
-   [Contributing](#contributing)
-   [License](#license)

## Description

The Core Module contains all the necessary functionalities to build a new Laravel application.

## Documentation

-   [Graph system](docs/GRAPH_SYSTEM.md): CRUD-aligned graph expand/search/stats framework for every CRUD-resolvable entity.
-   [Event orchestration](docs/EVENT_ORCHESTRATION.md): cross-module indexing and moderation events.
-   Transactional outbox: durable cross-system integration events with an application-replaceable publisher and queued delivery.
-   [ACL system](docs/ACL_SYSTEM.md): permission and row-level access control behavior.
-   [CRUD system](docs/CRUD_SYSTEM.md): generic CRUD pipeline and API behavior, including the facets and the aggregate route.
-   [API access control](docs/rag/API_ACCESS_CONTROL.md): guards, the `expose_api` switch, token authentication, default roles, related-record visibility, media projection, personal tokens and service accounts.
-   [Module import framework](docs/IMPORT_FRAMEWORK.md): abstract command and neutral services for module-owned external importers.
-   [Plan status](docs/PLAN_STATUS.md): the `plan-status` script, its options, the git dates and the module rules.

## Installation

If you want to add this module to your project, you can use the `joshbrw/laravel-module-installer` package.

Add repository to your `composer.json` file:

```json
"repositories": [
    {
        "type": "composer",
        "url": "https://github.com/getlaraplate/laracore.git"
    }
]
```

```bash
composer require joshbrw/laravel-module-installer getlaraplate/laracore
```

Then, you can install the module by running the following command:

```bash
php artisan module:install Core
```

## Keeping the `.env` aligned with `.env.example`

A `.env` starts from `.env.example`. When an update adds variables to the example, the `.env` files already deployed do not get them. Run, after every update and on the production `.env`:

```bash
php artisan env:fill
```

It lists the variables of `.env.example` that the `.env` does not define (a commented line counts as not defined, an empty `NAME=` as defined), autocompletes their names, asks the value of the one you pick (the example value is the default, the comments above it are the hint, secret names such as `*_KEY`, `*_SECRET`, `*_PASSWORD`, `*_TOKEN` are asked with a masked prompt) and appends it to the file at once. An empty name ends the loop. If anything was written to the application's own `.env`, it then offers to run `config:clear` and `config:cache`; do not leave a cached configuration on a machine that runs the test suite.

`--file=` and `--example=` point it at another pair of files, for example a copy of the production `.env`; the cache question is then not asked. Without a terminal to ask on (`--no-interaction`, or standard input that is not a terminal, as in `ssh host 'php artisan env:fill'` without `-t`) it only reports how many variables are missing and exits with `1`. It writes through a symlinked `.env` (Deployer, Envoyer layouts) to the file the link points to, keeps the mode and, for a superuser, the owner of the file, and builds on what the file holds when it writes, so an edit made while a prompt is open is kept. Accepting the default offered writes the example's own text, so a `"${APP_NAME}"` reference stays one.

## Configuration

```env
#users
AUTH_MODEL=App\Models\User				#authentication model

#locking
LOCKIN_LOCK_VERSION_COLUMN=lock_version			#column name for the lock version
LOCKIN_LOCK_AT_COLUMN=locked_at					#column name for the lock date
LOCKIN_LOCK_BY_COLUMN=locked_user_id			#column name for the lock owner (a users.id)
LOCKIN_LOCK_UNTIL_COLUMN=locked_until			#column name for the lock deadline; null means it never lapses
LOCKING_LEASE_TTL=900							#lifetime in seconds of the lease an edit form takes
LOCKING_CAN_BE_UNLOCKED=						#comma separated list of classes exempt when the above is false

#database
DB_EMULATE_PREPARES=false						#pgsql: one round trip per query instead of prepare + execute (Overrides\PostgresConnection binds booleans for it)

#https
FORCE_HTTPS=false								#enables HTTPS

#logging
GRAYLOG_LEVEL=error								#log level for graylog
GRAYLOG_URL=									#graylog url
GRAYLOG_PORT=12201								#graylog port

#permission
PERMISSION_MODEL=Modules\Core\Models\Permission	#permission model
ROLE_MODEL=Modules\Core\Models\Role				#role model
SUPERADMIN_ROLE=superadmin						#superadmin role name
ADMIN_ROLE=admin								#admin role name
GUEST_ROLE=guest								#guest role name
SUPERADMIN_USER=superadmin						#superadmin user name
ADMIN_USER=admin								#admin user name
GUEST_USER=anonymous							#guest user name

#queues
HORIZON_DOMAIN=									#horizon domain
HORIZON_PATH=									#horizon path
HORIZON_PREFIX=									#horizon prefix

#ai (deprecated - moved to AI module)
# AI configuration is now in the AI module. See Modules/AI/README.md for configuration.
# These variables are kept for backward compatibility but will be removed in future versions.
OPENAI_API_KEY=									#openai api key (deprecated - use AI module config)
OPENAI_API_URL=                                 #openai compatible api url (deprecated - use AI module config)
OPENAI_MODEL=                                   #openai model (deprecated - use AI module config)
OLLAMA_API_URL=                                 #ollama compatible api url (deprecated - use AI module config)
OLLAMA_MODEL="llama3.2:3b"						#ollama model (deprecated - use AI module config)

#search
SCOUT_DRIVER=typesense                          #actually supperted drivers with full functionalities (typesense, elasticsearch)
#embeddings are produced by the AI module: the model is the setting features.embeddings.model (provider:model), not an env var. Core's search.vector.dimensions|similarity|model are managed settings the AI model switch writes (ai:embeddings:switch).
SEARCH_ENGINE=elasticsearch						#default search engine
SEARCH_DATABASE_PG_TRGM_ENABLED=false             #enable PostgreSQL pg_trgm matching for the database Scout driver
SEARCH_ANALYZER_IT=italian                        #Elasticsearch analyzer for the it locale sub-field
SEARCH_ANALYZER_EN=english                        #Elasticsearch analyzer for the en locale sub-field
SCOUT_PREFIX=									#scout index prefix
SCOUT_QUEUE=true								#enable scout queue
SCOUT_QUEUE_NAME=indexing						#scout queue name
SCOUT_QUEUE_TRIES=3								#scout queue retry attempts (superseded by retryUntil while it is unexpired)
SCOUT_QUEUE_TIMEOUT=120							#scout queue timeout
SCOUT_QUEUE_BACKOFF=30,60,120					#scout queue backoff times
SCOUT_QUEUE_MAX_EXCEPTIONS=3					#real errors before an indexing job fails (rate-limit releases do not count)
EMBEDDINGS_QUEUE_RATE_PER_MINUTE=10				#embedding jobs per minute; raise for faster bulk backfills, keep low if the embedding service saturates
BULK_INDEX_BATCH=100							#optimistic batch size for bulk (scout:import) reindexing; the adaptive batcher shrinks it when the engine strains
SCOUT_QUEUE_RETRY_UNTIL_MINUTES=720				#retry window; lets rate-limited indexing jobs wait for a slot instead of dying with MaxAttemptsExceeded
SCOUT_IDENTIFY=false							#identify user in search engine

#elasticsearch
ELASTIC_INDEX_PREFIX=						#elasticsearch index prefix
ELASTIC_QUEUE_CONNECTION=sync				#elasticsearch queue connection
ELASTIC_QUEUE=indexing					#elasticsearch queue name
ELASTIC_QUEUE_TIMEOUT=300					#elasticsearch queue timeout
ELASTIC_QUEUE_TRIES=3						#elasticsearch queue retry attempts
ELASTIC_CONNECTION=default						#elasticsearch connection name
ELASTIC_HOST=localhost:9200						#elasticsearch host
ELASTIC_RETRIES=3								#elasticsearch retry attempts
ELASTIC_TIMEOUT=60								#elasticsearch timeout
ELASTIC_CONNECT_TIMEOUT=10						#elasticsearch connection timeout
ELASTIC_SSL_VERIFICATION=true					#elasticsearch SSL verification
ELASTIC_USERNAME=								#elasticsearch username
ELASTIC_PASSWORD=								#elasticsearch password
ELASTIC_LOG_ENABLED=false						#elasticsearch logging enabled
ELASTIC_LOG_LEVEL=error							#elasticsearch log level
ELASTIC_RETRY_ON_CONFLICT=3						#elasticsearch retry on conflict
ELASTIC_BULK_SIZE=500							#elasticsearch bulk size
ELASTIC_SUPPORTED_MAJOR_MIN=8					#lowest supported elasticsearch server major version
ELASTIC_SUPPORTED_MAJOR_MAX=8					#highest supported elasticsearch server major version
ELASTIC_SCOUT_DRIVER_REFRESH_DOCUMENTS=false	#elasticsearch scout driver refresh documents

#typesense
TYPESENSE_API_KEY=xyz							#typesense api key
TYPESENSE_HOST=localhost						#typesense host
TYPESENSE_PORT=8108								#typesense port
TYPESENSE_PATH=									#typesense path
TYPESENSE_PROTOCOL=http							#typesense protocol
TYPESENSE_CONNECTION_TIMEOUT_SECONDS=2			#typesense connection timeout
TYPESENSE_HEALTHCHECK_INTERVAL_SECONDS=30		#typesense healthcheck interval
TYPESENSE_NUM_RETRIES=3							#typesense number of retries
TYPESENSE_RETRY_INTERVAL_SECONDS=1				#typesense retry interval
TYPESENSE_INDEX_PREFIX=							#typesense index prefix
TYPESENSE_HOSTS=http://localhost:8108			#typesense hosts (comma separated)

#cache
CACHE_DURATION_SHORT=10							#short cache duration in seconds
CACHE_DURATION_MEDIUM=300						#medium cache duration in seconds
CACHE_DURATION_LONG=3600						#long cache duration in seconds

#app
APP_LOGO=										#application logo
APP_FAVICON=										#browser tab icon of the admin panel

#social login
FACEBOOK_CLIENT_ID=								#facebook client id
FACEBOOK_CLIENT_SECRET=							#facebook client secret
X_CLIENT_ID=									#x client id
X_CLIENT_SECRET=								#x client secret
LINKEDIN_OPENID_CLIENT_ID=						#linkedin openid client id
LINKEDIN_OPENID_CLIENT_SECRET=					#linkedin openid client secret
GOOGLE_CLIENT_ID=								#google client id
GOOGLE_CLIENT_SECRET=							#google client secret
GITHUB_CLIENT_ID=								#github client id
GITHUB_CLIENT_SECRET=							#github client secret
GITLAB_CLIENT_ID=								#gitlab client id
GITLAB_CLIENT_SECRET=							#gitlab client secret
BITBUCKET_CLIENT_ID=							#bitbucket client id
BITBUCKET_CLIENT_SECRET=						#bitbucket client secret
SLACK_CLIENT_ID=								#slack client id
SLACK_CLIENT_SECRET=							#slack client secret
SLACK_OPENID_CLIENT_ID= 						#slack openid client id
SLACK_OPENID_CLIENT_SECRET=						#slack openid client secret
SOCIALITE_REDIRECT= 							#socialite redirect
```

### API access configuration

These are config keys under `core.` (set them in the Core config, `Modules/Core/config/config.php`), not environment variables, except where a runtime setting is named:

| Key | Default | Meaning |
|-----|---------|---------|
| `core.expose_api` | `false` | Master switch of every `/api` route, Core and modules (ERP callbacks, SAO webhooks, MES machine data included). Off, they answer `403`. A runtime setting named `expose_api` (Filament > Settings, seeded by Core) overrides the default. |
| `core.api.rate_limit_per_minute` | `600` | Requests a minute per token, or per client address when there is no token. Also the number of failed bearer authentications a client address may make a minute before it gets `429`. |
| `core.api_tokens.max_lifetime_days` | `365` | Longest life of a personal access token. Service-account tokens may be non-expiring. |
| `core.media.signed_url_ttl_minutes` | `60` | Minutes a temporary media URL of the `api` projection stays valid, on a disk whose driver can sign URLs. |

The anonymous account is the user named by `GUEST_USER` (default `anonymous`). See [docs/rag/API_ACCESS_CONTROL.md](docs/rag/API_ACCESS_CONTROL.md) for the behaviour behind these keys.

### Aggregate route configuration

The CRUD aggregate route (`GET|POST /app/crud/aggregate/{module}/{entity}`, also under `/api/v1`) is bounded by the ceilings below. Each one is an environment variable read by the `aggregate` block of `Modules/Core/config/config.php`, as the config key `core.aggregate.*`. A value below 1 or not numeric is replaced by its default, so a wrong setting never switches a limit off. The behaviour behind them, and the errors they raise, are in [docs/CRUD_SYSTEM.md](docs/CRUD_SYSTEM.md#aggregate-route).

| Variable | Config key | Default | Meaning |
|----------|------------|---------|---------|
| `CORE_AGGREGATE_MAX_MEASURES` | `core.aggregate.max_measures` | `3` | Measures one request may compute. |
| `CORE_AGGREGATE_MAX_GROUPS` | `core.aggregate.max_groups` | `50` | Groups one request may return. A `limit` above it, or a grouping that gives more groups with no `limit`, is `TOO_MANY_GROUPS`. |
| `CORE_AGGREGATE_MAX_POINTS` | `core.aggregate.max_points` | `400` | Points one series may hold: a bucketed period gives one point per bucket. The series of the period and the series of its previous period (with `compare`) are each held to it, so a result with a comparison can hold up to twice this many. More is `PERIOD_TOO_LONG`. |
| `CORE_AGGREGATE_MAX_PERIOD_DAYS` | `core.aggregate.max_period_days` | `1096` | Longest period one request may span, in days. More is `PERIOD_TOO_LONG`. |
| `CORE_AGGREGATE_MAX_OBJECTS_PER_MESSAGE` | `core.aggregate.max_objects_per_message` | `6` | Reserved: nothing reads it yet. The route ignores it; `AggregateLimits` carries it for a later consumer. |
| `CORE_AGGREGATE_RATE_PER_MINUTE` | `core.aggregate.rate_per_minute` | `60` | Aggregate requests a minute per user (per client address for the anonymous user), through the `aggregate` limiter. Over it the route answers `429`. |

### Versioning configuration

`versioning.strategy.{table}` settings are runtime controls only for models that do not pin a strategy in code. When a model declares `versionStrategy = VersionStrategy::DIFF`, `ForcedVersionStrategySettings` excludes any historical matching row from the Settings resource and prevents recreating it through that form. The row is not deleted automatically.

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\HasVersions;

class ... extends Model
{
    use HasVersions;

	protected $versionable = [ /** versionable fields */ ];
	protected $dontVersionable = [ /** fields not versionable */ ];
}
```

### Advanced search filters

Core search applies filters inside the active engine before pagination. When a model exposes a searchable schema, public filters are accepted only on fields declared filterable or facetable.

Indexed relation fields use schema-declared dot paths such as `tags.id`. Elasticsearch receives nested queries, Typesense receives nested-field filter syntax, and the database driver uses the schema field option `relation` to translate the same filter to `whereHas` / `whereDoesntHave`. On relation fields, `!=` and `not in` mean anti-exists: no related indexed row may match the value/list.

### Portable text matching

Text matching is configured under `search.text_matching.defaults` and translated by each engine adapter. The options are granular so named profiles can remain replaceable presets instead of becoming part of the engine contract.

The database engine always provides case-insensitive prefix or substring matching. PostgreSQL can additionally use `strict_word_similarity()` after installing the trusted `pg_trgm` extension and setting `SEARCH_DATABASE_PG_TRGM_ENABLED=true`. Other database drivers report typo tolerance as degraded. Oracle intentionally uses the portable fallback: `UTL_MATCH` is not index-backed for generic retrieval over long text, while Oracle Text requires explicit `CONTEXT` indexes and a schema-aware adapter.

### Retrieval tuning profile

Orchestrated search fuses keyword, vector and hybrid rankings with parameters from the planner (L0). The `search.adaptive_tuning` setting (group `search`, seeded off) swaps in the committed, measured profile `config/search_tuning.php`, selected per query class (`identifier`, `short_keyword`, `multi_term`, `natural_language`). The profile shapes fusion and reranking only, never which strategies run; off, missing or invalid means the planner values are used unchanged. Every tuned response carries `meta['tuning']` with the profile version and the query class. Profile values are produced by `php artisan test:ai:tune-retrieval` (AI module) and committed by hand. Details: `docs/rag/SEARCH_RETRIEVAL_PIPELINE.md`.

### Elasticsearch configuration

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Search\Traits\Searchable;

class ... extends Model
{
    use Searchable {
        toSearchableArray as toSearchableArrayTrait;
    }

	protected function toSearchableArray(): array
    {
		// get default model data if you like
		$document = $this->toSearchableArrayTrait();
        // add your customizations
		return $document + [
            'id' => 'keyword',
            'title' => 'text',
            'published' => 'boolean',
            'created_at' => 'date',
			// ...
        ];
    }
}
```

### Validations configuration

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\HasValidations;

class ... extends Model
{
    use HasValidations;

	protected $rules = [
		'create' => [ /** rules for create */ ],
		'update' => [ /** rules for update */ ],
		'always' => [ /** rules for always */ ],
	];
}
```

### Validity configuration

`HasValidity` adds `valid_from` / `valid_to` datetime columns (via `MigrateUtils::timestamps(..., hasValidity: true)`) and helpers such as `isValid()`, `isExpired()`, `isScheduled()`, `isDraft()`, `publish()`, and `unpublish()`.

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\HasValidity;

class ... extends Model
{
    use HasValidity;

	protected static $valid_from_column = 'valid_from';
	protected static $valid_to_column = 'valid_to';
}
```

#### Query scopes and the expiring window

`HasValidity` declares the scopes `valid`, `expired`, `scheduled`, `draft`, `published`, `expiring`, `validAt`, `expiredAt` and `validityOrdered`.

All of them except `validityOrdered` are declared filter scopes (`#[FilterScope(client: true)]`), so an ACL or a CRUD request can name them (`{"scope": "valid"}`): see [docs/ACL_SYSTEM.md](docs/ACL_SYSTEM.md#scope-filters). `validityOrdered` orders and cannot be a filter.

`validAt($date)` and `expiredAt($date)` are the two that take a moment in time: the first returns what was in force then, the second what had already lapsed by then. `expired()` is `expiredAt(now())`.

`expiring` answers the question the other scopes do not: which rows are valid now but stop being valid soon. A row with no `valid_to` never expires and is never returned.

```php
// The model's own window
Licence::expiring()->get();

// An explicit one, in hours
Licence::expiring(24 * 30)->get();
```

The window is resolved nearest first: the argument, then the model's own `$expiring_within_hours`, then `core.validity.expiring_within_hours`.

| Setting | Default | Meaning |
| --- | --- | --- |
| `validity.expiring_within_hours` | `48` | Hours of lead time for `expiring()` when no window is given. A stored `0` or negative value reads as `1`. |

It is a runtime setting (Filament > Settings, group `validity`), read from config as `core.validity.expiring_within_hours`.

The default suits a record measured in days. A model whose rows live for months is expected to widen it for itself, rather than moving the default for everyone:

```php
class Licence extends Model
{
    use HasValidity;

    protected static int $expiring_within_hours = 720; // 30 days
}
```

The `Expiring` option in the Filament table filter calls the scope with no argument, so each resource shows what that model considers near.

#### Temporary users (`Core\Models\User`)

`User` uses `HasValidity` so accounts can expire automatically at `valid_to` without soft-delete or manual deactivation.

| Account type | `valid_from` | `valid_to` |
| --- | --- | --- |
| Permanent | `now()` (or start date) | `null` |
| Temporary | access start | access end |

```php
// Seven-day contractor account
$user->publish(now(), now()->addDays(7));

// Or direct assignment
$user->update([
    'valid_from' => now(),
    'valid_to' => now()->addDays(7),
]);
```

Seeded system users (`superadmin`, `admin`, `guest`, `system`) use `valid_from => now()` and `valid_to => null`. Enforce the window at login by checking `$user->isValid()` in Fortify/Socialite providers or auth middleware (not applied automatically on every query).

If you need to override the Core Module or dependencies configs you can publish them by running the following command:

## Upgrade notes

### API access control (guards, tokens, related records)

-   **The API switch is renamed.** The seeded setting `crud.expose_api` (config `core.crud.expose_api`) is now `expose_api` (config `core.expose_api`, default `false`). It gates every `/api` route of Core and of the modules, not only the CRUD routes: with it off, ERP provider callbacks, SAO webhooks and MES machine data answer `403`. Anything that writes the old key literally (scripts, tests) must write the new one; `CrudApiExposure::enable()` already does.
-   **Rebuild existing installations with `php artisan migrate:fresh --seed`.** There is no migration of roles or permissions: every permission now exists for both the `web` and the `api` guard, the users table gains `is_service_account`, and `personal_access_tokens` gains `allowed_cidrs`, all folded into their create migrations. Re-seeding an existing database is not enough: the seeder creates missing roles only and never revokes a grant, so the old `guest` role of `web` keeps its `select` permissions (it no longer affects `/api`, but an anonymous `/app` session can still read those entities). A freshly seeded `guest` on `web` holds no permission, and `guest` on `api` holds the public list. The anonymous user is cached: clear the cache after seeding if it was cached before its roles existed.
-   **Related records obey their own permission.** A user who read a relation through its parent without holding the related entity's `select` permission loses it (the seeded roles were given the related permissions their screens need). Clients that send `method` columns now get `400` unless the model declares the method in `crudComputedDependencies()`; no production model does.
-   Rebuild the CMS search indexes after upgrading (see the CMS README).

## PHP Attributes

This module uses PHP 8 attributes to improve type safety and follow modern PHP standards.

### Override Attribute

Use `#[Override]` when overriding parent methods for better type safety:

```php
use Override;

#[Override]
public function getAttribute($key): mixed
{
    // Your implementation
}
```


## Seeding and reconciliation

Production seeders run on **every release**, against a database that already has real operator
data in it. `Modules\Core\Seeding` (`Modules/Core/app/Seeding/`) is the mechanism that makes that
safe: it orders seeders by declared dependency instead of a flat priority number, reconciles rows
without clobbering values an operator changed after seeding, and removes settings whose owning
module is gone — never anything else.

### Dependency graph replaces `module.json` priority

A seeder opts into explicit ordering by implementing `DeclaresSeedDependencies`:

```php
<?php
namespace Modules\ERP\Database\Seeders;

use Modules\Core\Seeding\Contracts\DeclaresSeedDependencies;

final class ErpDatabaseSeeder extends Seeder implements DeclaresSeedDependencies
{
    public static function dependsOn(): array
    {
        return [\Modules\Core\Database\Seeders\CoreDatabaseSeeder::class];
    }
}
```

`dependsOn()` is only for edges *within the same module* or edges that are not already implied by
`module.json`. `SeedGraphBuilder` adds an implicit edge from every seeder of a module to every
seeder of each module it `requires` in `module.json`, so cross-module order does not need to be
restated. `SeedGraph::sort()` then performs a topological sort with a deterministic
`[module, seederClass]` tie-break, so the same set of seeders always produces the same order
regardless of discovery order.

This replaced an implicit `module.json` `priority` ordering that could silently violate a
module's own declared `requires`: `MES` declares `"requires": ["ERP"]` but carries a lower
`priority` (`2`) than `ERP` (`99`), so under priority-based ordering `MES` seeded before the `ERP`
data it depends on. The graph raises instead of degrading silently: `SeedGraphCycleException` on a
dependency cycle, `MissingSeedDependencyException` when a declared dependency belongs to a module
that is disabled or absent (a seeder cannot depend on a class the graph never discovered).

Seeder discovery is automatic: any `database/seeders/*.php` class of an enabled module is a node,
except classes whose basename starts with `Dev` (`DevDatabaseSeeder` and friends are never part of
the production graph). A seeder does not need to implement `DeclaresSeedDependencies` at all if
`module.json requires` already expresses everything it needs — `CoreDatabaseSeeder` is the
example: it declares no dependencies of its own.

### Declaring what to reconcile — `SeedDefinition`

`SeedDefinition` is a value object that tells `SeedReconciler` how to treat a set of rows for one
model:

```php
$outcome = app(SeedReconciler::class)->reconcile(
    SeedDefinition::for(Setting::class)
        ->identity(['name'])
        ->structural(['type', 'description', 'choices', 'is_internal'])
        ->initial(['value'])
        ->ownedBy('Core')
        ->rows($defaultSettings),
);
```

Module seeders build this exact definition through
`Seeder::internalSettingsDefinition($module, $rows)`, which stamps `is_internal` from the declaring
module's ownership: `true` when `is_laraplate_owned_module($module)` holds (`module.json`
`laraplate_owned`, or a `getlaraplate/lara*` composer name), `false` for a third-party module
shipping its own settings. Because the flag is structural, a re-seed also follows a module that
changes ownership.
`group_name` is deliberately not structural: it is written when the row is created and then
belongs to the operator, so a re-seed never undoes a regrouping done in the panel.

- **`identity`** must be exactly one column. A composite identity would need one `OR` clause per
  row, which defeats the fixed query budget the reconciler exists to provide;
  `identityColumn()` throws `LogicException` unless exactly one column is declared.
- **`structural`** fields follow the code: every release realigns them to whatever the seeder
  declares, overwriting any value an operator set directly.
- **`initial`** fields are written once, at creation, and never touched again by a later run —
  this is how a seeded default (e.g. a `Setting`'s `value`) stays an operator's to change.
- **`rows()`** normalizes every empty string (`''`) to `null` and throws `InvalidArgumentException`
  if a row is missing the identity column or its identity value is null/empty/absent. Do this
  normalization here, not in a model saving hook: reconciliation writes via `upsert()`, which never
  fires Eloquent events.

### `SeedReconciler` and drift

`SeedReconciler::reconcile()` runs a fixed number of queries regardless of row count: one read
(existing rows keyed by identity), one `upsert()` for created/realigned rows, one `restore()` for
soft-deleted rows found among the declared set, and one baseline-backfill `upsert()` for rows that
predate the `seeded_value` column — each write only runs when its row set is non-empty. Because it
writes through `upsert()`, **no Eloquent event fires** (no observers, no `saving`/`saved`) — this is
deliberate, not an oversight, and is why `SeedDefinition::rows()` does its own normalization instead
of relying on a model hook. The `$update` list passed to the realignment `upsert()` is exactly
`$definition->structural` — nothing else. `value` and `seeded_value` must never be added to it: doing
so would silently overwrite an operator's customization on every release.

For `Setting`, drift is **computed**, never asserted: `core_settings.module` records the owning
module (`null` when the row was not written by a seeder) and `core_settings.seeded_value` records
the last value a seeder wrote there. A setting has drifted when `value !== seeded_value`, compared
via `ValueComparator::equal()` (which normalizes JSON key order, numeric-string vs. numeric, and
`BackedEnum` vs. its backing value so none of those read as drift). `seeded_value IS NULL` means the
seeder never wrote that row — for example, a row created before this column existed.

### Seeder console output

A seeder prints one header and one line per step, whatever the step seeds. `logOperation($model)`
opens the step (`Creating default <table>` or `Updating default <table>`); `logOutcome($created,
$realigned, $unchanged)` closes it with `nothing to update` when no row changed, otherwise with the
three counts. `logReconciliation($outcome)` does the same for a `ReconciliationOutcome`, counting
restored rows as realigned. Both come from `HasSeedersUtils`, so a module seeder extending
`Modules\Core\Overrides\Seeder` reports in the same shape as Core. Row names are not printed. The
only detail beyond the counts is the superadmin password, shown once, when that user is created.
Warnings about a missing prerequisite (a role or permission a step needs) are still printed.

With `APP_DEBUG` on, `db:seed` and `module:seed` print one benchmark line (`⚡ BOOT … TIME … MEM …
SQL …`) at the end of the run, with the totals of every seeder and of the nested `db:seed` calls.
The outermost of the two commands owns it (`BenchmarksSeedingRun`): while it runs, the
`Seeder::RUN_BENCHMARK_BINDING` key is bound and a seeder skips its own benchmark. A seeder run
outside these commands, for example from a test, still benchmarks itself. Queries are counted by
a listener, not by the query log, so a long dev seed does not hold every query in memory.

### Cleanup by module state

`SettingsCleaner::clean()` runs once, after every node in the graph has succeeded, and only ever
touches rows a seeder actually wrote:

| `module` | `seeded_value` | Module state | Drifted (`value !== seeded_value`) | Outcome |
| --- | --- | --- | --- | --- |
| `null` | any | — | — | never a candidate, always preserved |
| any | `null` | — | — | never a candidate, always preserved |
| set | set | Enabled | any | preserved |
| set | set | Disabled | No | hard deleted (`forceDelete()`) |
| set | set | Disabled | Yes | soft deleted (`delete()`) — operator's customization kept, recoverable |
| set | set | Absent (module directory gone) | any | hard deleted (`forceDelete()`) |

The `whereNotNull('module')->whereNotNull('seeded_value')` pair on the candidate query is the
safety mechanism, not an optimization: a row without both is never a seeder-owned row and must
never be a deletion candidate. Keep that filter in the query, not in the loop body, so a later
refactor of the loop cannot bypass it.

Module state itself is resolved by `ModuleStateResolver`, which uses `Module::find($name)?->isEnabled()`
rather than an array lookup — see [Gotchas](#gotchas-for-seeder-and-test-authors) below.

### Resuming a failed run

`SeedOrchestrator::run()` executes every node in graph order inside its own transaction, stops at
the **first** failure (a release must not apply half a configuration), and records progress per
node in the `core_seed_runs` ledger (`SeedLedger`, table `CoreTables::SeedRuns`). `db:seed --resume`
(declared by `Modules\Core\Console\SeedCommand`) re-runs the last failed `run_id` and skips any node
already marked `succeeded` under it, so a fix-and-retry does not repeat completed work.

`--skip-unchanged` is deliberately **not implemented**. The ledger already records a `content_hash`
per succeeded node, but today that hash is a placeholder — `hash('xxh128', $node->seederClass)`,
the class name only, not a digest of what the node would actually write. A `--skip-unchanged` flag
built on that hash would skip nodes whose content changed but whose class name did not, which is
the opposite of what the flag would promise. Do not wire a skip decision to `content_hash` until it
hashes the node's actual definitions.

### Dev data and volume scaling

`db:seed --dev` (declared by `Modules\Core\Console\SeedCommand`) runs the `Dev*` seeders instead of
the production graph: it dispatches `DevDatabaseSeeder`, which discovers every enabled module's
`database/seeders/Dev*.php` class and fills the database with fake bulk data. These seeders extend
`Modules\Core\Helpers\BatchSeeder` and pass a hardcoded target count (their "max" size) to
`createInBatches()` / `createInParallelBatches()`.

Four mutually-exclusive flags scale that fake volume without editing the seeders:

| Flag | Multiplier | Example (`TARGET_COUNT_CONTENTS = 500_000`) |
| --- | --- | --- |
| `--pico` | ×0.001 | 5 00 |
| `--micro` | ×0.01 | 5 000 |
| `--min` | ×0.1 | 50 000 |
| `--mid` | ×0.5 | 250 000 |
| `--max` | ×1.0 | 500 000 |

- The default, when no flag is given, is `--pico` (1% — a fast, workable dev dataset).
- Precedence is fixed as `pico → micro → min → mid → max`: the first one present wins (so `--min --mid`
  resolves to `--min`), regardless of the order typed on the command line.
- `SeedCommand` resolves the factor **once**, from the real invocation flags, and publishes it on the
  container under `BatchSeeder::SCALE_CONTAINER_KEY` before dispatching (clearing it in a `finally`).
  `BatchSeeder` reads the factor from there and multiplies each target count. It is **not** read off
  `$this->command`: `db:seed` is a shared container instance whose input is rebound by the nested
  `module:seed → db:seed` calls the dev seeders make, so a seeder reading `option('min')` later would
  see that mutated input, not the operator's flags. A positive target is floored to at least one
  record, so a scaled-down run never leaves a table empty for a dependent seeder; a zero target
  stays zero.
- Only fake bulk volume scales. Reference/settings data seeded via `module:seed` inside the dev
  seeders is **not** scaled — partial reference data would break dependencies.
- The flags are no-ops outside `--dev`: the production graph seeds fixed reference data only, and the
  container key is never published.

The same flags work per module: `module:seed <Module> --dev [--pico|--micro|--min|--mid|--max]`
(`Modules\Core\Console\ModuleSeedCommand`, an override of nwidart's `module:seed`) runs that module's
`Dev{Module}DatabaseSeeder` alone, publishing the scale the same way. Both commands share
`Modules\Core\Console\Concerns\ResolvesDevSeedScale` so the flag set, precedence, and container
publishing live in one place.

### Per-node atomicity — what "rolled back" does and does not mean

Each node runs inside a transaction on the **default** database connection only, opened via the
injected `DatabaseManager`. This is a documented compromise: `SeedNode` carries no connection
information, and a seeder is free to write to any model on any connection, so the orchestrator has
no general way to know which connection(s) a given node touches. If a node fails:

- Writes it made on the **default connection** are rolled back.
- Writes it made on **any other connection** are **not** rolled back by this mechanism, and must be
  verified manually before resuming. A seeder that writes to a non-default connection is
  responsible for wrapping those writes in its own transaction on that connection — the project's
  connection-affinity tests already forbid `DB::transaction(` inside a seeder file, so this is the
  existing convention, not a new obligation.

The failure message printed to the console and written to the log repeats this caveat verbatim, so
whoever is reading a broken release sees it without reading the source.

### Gotchas for seeder and test authors

- **`Setting::query()->forceCreate()` silently no-ops.** `Setting::requiresApprovalWhen()` shadows
  `HasApprovals`'s version and returns `true` for any fillable-field change other than
  `description` and `group_name`, so the approval
  package's saving listener cancels every direct create/update on `Setting` — including
  `forceCreate()`. When a seeder test needs a real, already-persisted `Setting` row (for example to
  simulate one written before the reconciler existed), use
  `Setting::factory()->persistedWithoutApprovalCapture()->create([...])` instead.
- **`nwidart/laravel-modules` keys its module arrays by lowercase name; `core_settings.module`
  does not.** `Nwidart\Modules\FileRepository::scan()` keys `all()`/`allEnabled()` by
  `strtolower($name)`, but `core_settings.module` stores the module's declared case (`Core`, `MES`,
  ...). `array_key_exists('Core', Module::allEnabled())` is therefore always `false`, misclassifying
  every real module as absent. Resolve module state with `Module::find($name)?->isEnabled()`
  instead — `find()` lowercases internally. `ModuleStateResolver` already does this; reach for it
  rather than re-deriving module state from the array helpers.

## Features

### Requirements

-   PHP >= 8.5
-   Laravel 12.0+
-   **PHP Extensions:**

    -   `ext-curl`: Provides support for URL requests.
    -   `ext-json`: Enables JSON serialization and deserialization.
    -   `ext-redis`: Allows interaction with Redis databases.
    -   `ext-pcntl`: Provides process control functions.
    -   `ext-posix`: Offers access to POSIX functions.
    -   `ext-intl`: Provides internationalization services.
    -   `ext-sockets`: Provides low-level networking interface.

### Installed Packages

The Core Module utilizes several packages to enhance its functionality. Below is a list of the key packages included in the `composer.json` file:

-   **Database and ORM:**

    -   [doctrine/dbal](https://github.com/doctrine/dbal): Database abstraction layer.
    -   [laravel/fortify](https://github.com/laravel/fortify): Authentication scaffolding.
    -   Model versioning is part of Core (`HasVersions`, `Version`), derived from overtrue/laravel-versionable, which is no longer a dependency (see Third-party code).
    -   [spatie/laravel-permission](https://github.com/spatie/laravel-permission): Roles and permissions.
    -   [staudenmeir/laravel-adjacency-list](https://github.com/staudenmeir/laravel-adjacency-list): Hierarchical data handling.
    -   [spatie/eloquent-sortable](https://github.com/spatie/eloquent-sortable): Ordering helpers for Eloquent.

-   **UI / Admin:**

    -   [filament/filament](https://github.com/filamentphp/filament): Admin panel toolkit (v4).
    -   Built-in Filament environment indicator (Core plugin): env badge + module versions dropdown for super-admins.
    -   `filament:make-resources` — scaffold Filament resources for `App` or custom (non-owned) modules; injects `HasTable` / `HasForm` / `HasRecords` via rebound ClassGenerators. See `docs/rag/MODULE.md` (Filament scaffolding).

-   **Media:**

    -   [spatie/laravel-medialibrary](https://github.com/spatie/laravel-medialibrary): Media handling for models.
    -   Core owns the media foundation: the shared `vend_media` table (guarded migration), the app-wide `media_model` (`Modules\Core\Models\Media`, soft-delete + version aware with a computed `expires_at`), the `file_namer` (`Modules\Core\Helpers\MediaFileNamer`), and the generic soft-delete-aware lifecycle trait `Modules\Core\Helpers\HasMedia`. Any module that needs attachments uses `HasMedia`; rows live in `vend_media`. The full `config/media-library.php` is Core-owned.
    -   Core also exposes a generic media HTTP API (`core.crud.media.*`) with a token-keyed pending bucket (`Modules\Core\Models\MediaDraft`, table `core_media_drafts`) for CREATE forms; stale drafts are pruned daily by `core:prune-media-drafts`.
    -   Env: `MEDIA_DISK` (default `media-library`), `MAX_FILE_SIZE` (bytes, default 10MB), `MEDIA_QUEUE` (default `media-library`), `QUEUE_CONVERSIONS_BY_DEFAULT`, `QUEUE_CONVERSIONS_AFTER_DB_COMMIT`, `IMAGE_DRIVER` (default `imagick`), `FFMPEG_PATH`, `FFPROBE_PATH`, `MEDIA_DOWNLOADER_SSL`, `MEDIA_PREFIX`.
    -   Setting `media.draft_ttl_hours` (group `media`, default 24): hours a pending-media draft is kept before `core:prune-media-drafts` removes it; `0` (or a negative value) disables pruning.

-   **Logging and Monitoring:**

    -   [hedii/laravel-gelf-logger](https://github.com/hedii/laravel-gelf-logger): Graylog via GELF.
    -   [laravel/horizon](https://github.com/laravel/horizon): Queue dashboard and management.
    -   **Error fingerprinting** (`app/Logging/Fingerprint`): a dependency-free, ordered normalization rule chain (`StripStackTraces`, `CollapseVolatilePayloads`, `CollapseSqlState`, `SubstituteUuidIpHex`, value-position-only `SubstituteNumbersInValuePosition`) plus a `Fingerprinter` that hashes `kind+module+class+file+function+normalized message` — the line is deliberately excluded so a refactor does not fork a group, and a 404 and a 500 stay distinct. `GelfErrorFingerprintResolver` (in-process) and SAO's payload path share this chain, so one error yields one key however it is captured.

-   **User Management:**

    -   [lab404/laravel-impersonate](https://github.com/404labfr/laravel-impersonate): User impersonation.
    -   [laravel/socialite](https://github.com/laravel/socialite): OAuth providers.

-   **API and Documentation:**

    -   [wotz/laravel-swagger-ui](https://github.com/wotzebra/laravel-swagger-ui): Swagger UI hosting.
    -   [mtrajano/laravel-swagger](https://github.com/mtrajano/laravel-swagger): Swagger annotations.

-   **Search and Caching:**

    -   [elasticsearch/elasticsearch](https://github.com/elasticsearch/elasticsearch): Elasticsearch client.
    -   [theodo-group/llphant](https://github.com/theodo-group/llphant): Elasticsearch indexing helpers.
    -   [babenkoivan/elastic-scout-driver](https://github.com/babenkoivan/elastic-scout-driver): Scout driver for Elasticsearch.
    -   [babenkoivan/elastic-scout-driver-plus](https://github.com/babenkoivan/elastic-scout-driver-plus): Enhanced Elasticsearch features.
    -   [typesense/typesense-php](https://github.com/typesense/typesense-php): Typesense client.
    -   [laravel/scout](https://github.com/laravel/scout): Scout full-text abstraction.

-   **Spatial Data:**

    -   [matanyadaev/laravel-eloquent-spatial](https://github.com/matanyadaev/laravel-eloquent-spatial): Spatial types for Eloquent.

-   **Development and Testing** (declared by the application, not by this module — the module's
    `require-dev` was removed on 2026-09-15 and the toolchain lives in the root `composer.json`):

    -   [pestphp/pest](https://github.com/pestphp/pest) (+ stressless, type-coverage, laravel plugins).
    -   [laravel/pint](https://github.com/laravel/pint): Code style fixer.
    -   [nunomaduro/phpinsights](https://github.com/nunomaduro/phpinsights): Quality insights.
    -   [peckphp/peck](https://github.com/peckphp/peck): Typo checker.
    -   [rector/rector](https://github.com/rectorphp/rector): Automated refactoring.
    -   [driftingly/rector-laravel](https://github.com/driftingly/rector-laravel): Rector rules for Laravel.
    -   [larastan/larastan](https://github.com/nunomaduro/larastan): PHPStan for Laravel.
    -   [barryvdh/laravel-ide-helper](https://github.com/barryvdh/laravel-ide-helper): IDE helpers.
    -   [laravel/boost](https://github.com/laravel/boost): Local dev helper.
    -   [laravel/pail](https://github.com/laravel/pail): CLI log viewer.

### Environment (principali variabili)

-   Feature toggles: `FORCE_HTTPS`. User and CRUD toggles are runtime settings (Filament > Settings), not env vars: `auth.email_verification.enabled`, `auth.registration.enabled`, `auth.two_factor.enabled`, `auth.licenses.enabled`, `auth.social_login.enabled`, `auth.passkeys.enabled`, `crud.dynamic_entities`, `expose_api` (gates every `/api` route, Core and modules), `soft_deletes.expiration_days` (0 = never purge), `search.vector.enabled` (embeddings generation requires AI module). Search ranking is tuned from the `search` settings group too: `search.reranker.enabled`, `search.reranker.top_k`, `search.reranker.weight` (rerank blend, seeded `0.6`) and `search.adaptive_tuning` (seeded off: applies the committed `config/search_tuning.php` profile per query class; see `docs/rag/SEARCH_RETRIEVAL_PIPELINE.md`). Settings are listed without the module prefix (the module is a column) and read from config as `core.<name>`.
-   Timing settings (runtime, Filament > Settings, read as `core.<name>`; a stored `0` or negative value reads as `1`, except `media.draft_ttl_hours`, where it disables pruning): `media.draft_ttl_hours` (pending-media draft TTL, default 24), `validity.expiring_within_hours` (lead time for the `expiring` scope, default 48), `filament.tabs_counts_ttl_seconds` (seconds the backoffice caches tab counts and filter options on the Modifications, Permissions and Settings lists, default 60).
-   CRUD aggregate route: `CORE_AGGREGATE_MAX_MEASURES` (default 3), `CORE_AGGREGATE_MAX_GROUPS` (50), `CORE_AGGREGATE_MAX_POINTS` (400), `CORE_AGGREGATE_MAX_PERIOD_DAYS` (1096) and `CORE_AGGREGATE_RATE_PER_MINUTE` (60), plus `CORE_AGGREGATE_MAX_OBJECTS_PER_MESSAGE` (6, reserved: nothing reads it yet); see [Aggregate route configuration](#aggregate-route-configuration).
-   Standard stack: `DB_*`, `REDIS_*`, `SESSION_*`, `CACHE_STORE=redis`, `CACHE_PREFIX`, `QUEUE_CONNECTION=redis`, `HORIZON_MEMORY_LIMIT`, `FILESYSTEM_DISK`, `LOG_*`.

### Additional Functionalities

The Core Module includes built-in features such as:

-   User management with multi-level roles and permissions.
-   Temporal user validity (`valid_from` / `valid_to` on `users` for temporary accounts that auto-expire).
-   Email verification for new users.
-   Command-line tools for user registration and model management.
-   Redis caching for improved performance.
-   Automatic indexing of entities with Elasticsearch (embeddings generation requires AI module).
-   **Event orchestration** for search indexing and modification moderation ([docs/EVENT_ORCHESTRATION.md](docs/EVENT_ORCHESTRATION.md)).
-   **Adaptive search matching** user/API behavior ([docs/rag/SEARCH_MATCHING_USER.md](docs/rag/SEARCH_MATCHING_USER.md)) and developer/operator integration ([docs/rag/SEARCH_MATCHING_DEVELOPER.md](docs/rag/SEARCH_MATCHING_DEVELOPER.md)).
-   Enhanced Swagger documentation generation.
-   Utilities for translations and model versioning.
-   Support for Laravel Octane and Horizon for improved performance and queue management.
-   Multi entities and connections crud endpoints with standardized requests parameters
-   API access control: one guard, one switch and one optional token authentication for every `/api` route, permissions per guard, personal tokens and service accounts, related records that obey their own permission and ACL, attributes hidden from anonymous callers and a safe media projection ([docs/rag/API_ACCESS_CONTROL.md](docs/rag/API_ACCESS_CONTROL.md))
-   Strongly validated requests for Core routes
-   Default common Response Formatter
-   App settings and configurable Cron-Jobs on db tables
-   Automatic localization set on user request
-   Preview middleware for pending models approvals and multiuser approval system
-   Models versioning functionalities, with rollback functionalities
-   Dynamic entities for non mapped models
-   Dynamic gridutils for non mapped models
-   CRUD API for non mapped models
-   CRUD-aligned graph expand/search/stats endpoints for every CRUD-resolvable entity ([docs/GRAPH_SYSTEM.md](docs/GRAPH_SYSTEM.md)).
-   User licenses
-   Locking system for models
-   Graylog logging
-   Social login integration with multiple providers
-   Spatial data support for geographical applications
-   Composite primary keys support
-   Adjacency list pattern for hierarchical data structures
-   Vector search capabilities with AI-powered embeddings
-   Typesense search engine integration

## Scripts

The Core Module provides several useful scripts for development and maintenance:

### Code Quality and Testing

**Run everything from the application root, not from the module.** The module has no
runner of its own: it declares no `require-dev`, ships no `phpunit.xml`, and has no
`vendor/` directory. Its test cases extend the application's, and the application's
`phpunit.xml` globs `Modules/*/tests/...` into the three suites, so a new module needs no
configuration to be picked up.

```bash
php artisan test --compact Modules/Core/tests        # the whole module
php artisan test --compact Modules/Core/tests/Unit   # one suite of it
php artisan test --compact --testsuite=Unit          # the fast suite, every module

composer test                 # the full test and quality pipeline
composer test:type-coverage   # type coverage (target: 100%)
composer test:typos           # typo check
composer test:lint            # Pint + Rector dry-run
composer test:types           # PHPStan
composer test:refactor        # Rector
```

**The three suites** are classified by the bootstrap each test needs, not by what it
tests: `Unit` runs under a minimal TestCase with no application and no database;
`Integration` and `Feature` run under the full shell. `tests/UnitShell` holds what needs
the shell *without* a wrapping transaction — tests that manage their own database
lifecycle — and the `Integration` suite globs it.

**Test database:** tests use an in-memory SQLite database, so `pdo_sqlite` is required
(on Arch: `php-sqlite`).

**Memory:** the full suite needs more than the default 2G and dies about two thirds of
the way through. Run it as `php -d memory_limit=-1 vendor/bin/pest --compact`, or per
suite.

### Code Quality Tools

```bash
# Code style and IDE helpers
composer lint               # Fix code style and generate IDE helpers

# Static analysis
composer check              # Run PHPStan analysis
composer check:debt         # Count the errors the baseline is holding
composer fix                # Run PHPStan analysis with auto-fix
composer refactor           # Run Rector refactoring
```

`composer check` includes `phpstan-baseline.neon`, so it answers one question:
did this change make things worse. `[OK] No errors` means nothing new, not
nothing wrong. It is the one to run before a commit and in CI.

`composer check:debt` runs the same analysis with that include removed and
reports what is still frozen, broken down by identifier with the number of
distinct places each one comes from. A large count over few places is a single
fact reported once per class using a trait, which is usually where the cheap
fixes are. Add `-- --quiet` for the number alone.

Never add an entry to the baseline by hand. Fix the code, or, when a rule
genuinely cannot see something that is correct, add a scoped `ignoreErrors` entry
to `phpstan.neon` with the reason next to it, then regenerate:

```bash
vendor/bin/phpstan analyse --memory-limit=4G --generate-baseline phpstan-baseline.neon
```

### Version Management

Releases are cut by the maintainers with private tooling; do not bump versions or edit `CHANGELOG.md` by hand.

### Plan status

Lists the development plans of a repository, or of a whole workspace, with their progress, their modules and, with `--git-dates`, when their work started and finished. Run it from the application root:

```bash
composer run plan-status -- --open                  # plans still open
php scripts/plan-status.php --format=json --git-dates
```

Options, date rules and module rules are in [`docs/PLAN_STATUS.md`](docs/PLAN_STATUS.md).

### Third-party code

Core contains code derived from these MIT-licensed libraries, which it does not require as dependencies. Each license, with the upstream commit the code was taken from, is kept in [`LICENSES/`](LICENSES/), and the derived files carry a note in their header:

-   [sfolador/laravel-locked](https://github.com/sfolador/laravel-locked): record locking (`app/Locking`), see [`LICENSES/laravel-locked.md`](LICENSES/laravel-locked.md)
-   [reshadman/laravel-optimistic-locking](https://github.com/reshadman/laravel-optimistic-locking): optimistic locking (`HasOptimisticLocking`), see [`LICENSES/laravel-optimistic-locking.md`](LICENSES/laravel-optimistic-locking.md)
-   [cloudcake/laravel-approval](https://github.com/cloudcake/laravel-approval): approvals (`HasApprovals`, `Modification`, `ModificationVoteService`), see [`LICENSES/laravel-approval.md`](LICENSES/laravel-approval.md)
-   [overtrue/laravel-versionable](https://github.com/overtrue/laravel-versionable): versioning (`HasVersions`, `Version`, `VersionStrategy`), see [`LICENSES/laravel-versionable.md`](LICENSES/laravel-versionable.md).

Core also takes inspiration from, without deriving code from:

-   [vicgutt/laravel-inspect-db](https://github.com/VicGUTT/laravel-inspect-db): schema inspection (`app/Inspector`)

## Contributing

If you want to contribute to this project, follow these steps:

1. Fork the repository.
2. Create a new branch for your feature or correction.
3. Send a pull request.

## License

Core Module is open-sourced software licensed under the [GNU AGPL v3](https://www.gnu.org/licenses/agpl-3.0.html).

Copyright (c) 2025 Marco P. The full licence text is in [LICENSE](LICENSE).

## TODO and FIXME

This section tracks all pending tasks and issues that need to be addressed in the Core Module.

### High Priority

- [ ] **Database Compatibility Testing** - `Modules/Core/app/Models/DynamicEntity.php:104`
  - Test DynamicEntity with Oracle and SQL Server databases
  - Current implementation may not be fully compatible

- [ ] **Authentication Events Fix** - `Modules/Core/app/Models/Concerns/HasValidations.php:97`
  - FIXME: No events before retrieved, current implementation queries and then checks permissions
  - Need to implement proper event handling for user authorization

- [ ] **Development Middleware Cleanup** - `bootstrap/app.php:51`
  - TODO: Remove temporary middleware removals at end of development
  - Currently removes ValidateCsrfToken, EnsureEmailIsVerified, and AuthenticateSession

### Medium Priority

- [ ] **Database Index Optimization** - `Modules/Core/database/migrations/2019_05_31_042934_create_versions_table.php:26`
  - TODO: Consider adding index on versionable_type and versionable_id columns
  - Evaluate performance impact and implement if beneficial

- [ ] **Strict Mode Configuration** - `Modules/Core/app/Providers/CoreServiceProvider.php:298`
  - TODO: Strict mode prevents eager loading, application not yet ready
  - Need to review and implement proper eager loading strategies

- [ ] **CRUD Helper Relations** - `Modules/Core/app/Crud/CrudHelper.php:52`
  - TODO: Missing columns for relations when foreign key is on main table
  - Need to implement proper relation handling

- [ ] **Filter Grouping** - `Modules/Core/app/Crud/CrudHelper.php:99`
  - TODO: Need to implement filter disassembly and grouping for single relations
  - Current implementation may not handle complex filter scenarios properly

- [ ] **Sublevel Validation** - `Modules/Core/app/Crud/CrudHelper.php:255`
  - TODO: Current validation only works for first sublevel
  - Need to extend to support multiple sublevels

### Low Priority

- [ ] **Preview Record Management** - `Modules/Core/app/Http/Controllers/CrudController.php:304`
  - TODO: How to handle record preview? What to do with pending changes?
  - Need to implement proper preview functionality

- [ ] **Versioning Implementation** - `Modules/Core/app/Models/Concerns/HasVersions.php:161,166`
  - TODO: May need override for multiple primary keys
  - TODO: Complete implementation for versioning functionality

- [ ] **Entity Definition Testing** - `Modules/Core/app/Grids/Definitions/Entity.php:323,779`
  - TODO: May induce false paths if same sub-name exists in different sub-relations
  - TODO: Verify implementation, currently only sketched

### Notes

- Most TODO items are related to edge cases and advanced features
- Several items require testing with different database systems
- Some components need completion of implementation details
- Priority should be given to high-priority items that affect core functionality
