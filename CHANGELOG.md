# Changelog

All notable changes to this project will be documented in this file.

## [2.4.0] - 2026-11-27

### 🚀 Features

- *(search)* Add the search registry storage
- *(search)* Record searches of guests and readers after the response
- *(search)* Prune the search registry and document it
- *(users)* Add nullable timezone column to the users table
- *(filament)* Show date-times in the timezone of the signed in user
- *(users)* Edit language and timezone from the profile page and the API
- *(core)* Save the browser timezone, add DateFormatter and document dates

### 🐛 Bug Fixes

- *(search)* Express a null equality as a missing field on Elasticsearch
- *(search)* Apply default search filters on every search path
- *(search)* Never let the search registry change or fail the search response
- *(search)* Skip a null equality on Typesense instead of failing the search
- *(search)* Apply default search filters last through one helper
- *(search)* Remove the PHPStan errors the registry added
- *(import)* Store record origin source_updated_at as datetime

### 📚 Documentation

- *(search)* State which timestamps the registry keeps

### 🧪 Testing

- *(seeding)* Pick a versioning model when stamping a derived setting
- *(core)* Pin the UTC contract of the application and the API dates

## [2.3.0] - 2026-11-27

### 🚀 Features

- *(tests)* Add db2-spike.php for testing DB2 ODBC connections and schema management

## [2.2.1] - 2026-11-27

### 🐛 Bug Fixes

- *(core)* List a license without a user as unassigned
- *(core)* Keep a new place point on spatial drivers and align the decimals to it
- *(core)* Clear the place decimals when its point is cleared on spatial drivers

## [2.2.0] - 2026-11-26

### 🚀 Features

- *(core)* Move a subtree to the parent its column names, so plain saves converge

## [2.1.0] - 2026-11-26

### 🚀 Features

- *(core)* Serialize closure-table writers and link parents missing from the closure

### 🐛 Bug Fixes

- *(core)* Take the hierarchy lock with a locking read before the snapshot read

## [2.0.1] - 2026-11-25

### 🐛 Bug Fixes

- *(core)* What typed model columns found in Core

## [2.0.0] - 2026-11-25

### 🚀 Features

- *(console)* Models:declare-columns writes each model's column tags
- *(console)* Declare the fields translatable models read from their translation
- *(core)* Closure table maintainer and migration helper
- *(core)* Owner cascades keep hierarchy closures in step
- *(contracts)* Versioned and searchable models declare their contracts

### 🐛 Bug Fixes

- *(versioning)* Share one strategy cache across every versioned model
- *(phpstan)* Type the Core traits so their errors stop repeating per class
- *(helpers)* User_class() answers Core's User
- *(translations)* HasTranslations honours ITranslatableModel
- *(console)* Read tags whose type has spaces in its generics
- *(phpstan)* Type Core's models and the contracts other modules wait for
- *(phpstan)* Type Core's CRUD, cache labels and relation overrides
- *(core)* Bounded memory and safer inputs in the closure maintainer
- *(core)* Facets refuse black-listed relations
- *(core)* Closure kept in step when a delete is cancelled, deterministic sibling order
- *(core)* Children re-parented during a delete keep their links
- *(core)* Purging soft deletes keeps the closure in step
- *(core)* Taxonomy owners keep the closure in step
- *(core)* Owner cascade guards compare foreign key chains
- *(core)* Drop a batch path once what it was built from changes
- *(core)* Settings are read on behalf of the application

### 🚜 Refactor

- *(core)* Closure table trait with the full hierarchy relations
- *(core)* Roles and taxonomies on the closure table
- *(core)* [**breaking**] Compute no hierarchy paths
- *(core)* [**breaking**] Drop the name path of TreeCollection

### 📚 Documentation

- *(models)* Every model declares its columns as @property tags
- *(models)* Declare the json and array columns too
- *(models)* Declare translated and proxied attributes, and type factory keys
- *(core)* Document closure-table hierarchies
- *(core)* Correct the closure hierarchy docs
- *(core)* [**breaking**] Document the upgrade of an existing install

### ⚡ Performance

- *(core)* Hydrate closure relatives without model mutators
- *(core)* Let eager closure lineages enter the model table by key
- *(core)* The tree skips the redundant per-row select check of its nodes
- *(core)* Read the closure row off the node without a pivot model
- *(core)* Write tree paths without the model mutators
- *(core)* Give a collection of taxonomies their paths in one batch
- *(core)* Keep the eager lineage sub-select on an index range on MySQL
- *(core)* Tree paths above a node come from the rows already loaded
- *(core)* Read the batch path ancestors with their closure rows in one query
- *(core)* Let the batch path join enter the taxonomies by key
- *(core)* Tree paths below a node come from the rows already loaded

### 🎨 Styling

- *(filament)* Apply Pint to HasTable

### 🧪 Testing

- *(inspector)* Let the mocked database manager list its connections
- *(overrides)* State the uncached configuration the merge test relies on
- *(core)* Characterize role inheritance through a trashed role
- *(core)* Pin the depth of the detached nested row
- *(core)* Purge check on batch seeder without a Redis server

### ⚙️ Miscellaneous Tasks

- Remove the patch backups committed by mistake
- *(core)* Drop staudenmeir/laravel-adjacency-list

## [1.83.0] - 2026-11-24

### 🚀 Features

- *(cache)* Cache CRUD responses through a morphTo on indexes with row labels
- *(cache)* Warn when the Redis of the label index may evict its keys
- *(soft-deletes)* Switching soft deletes off purges the bin, after a confirmation
- *(locking)* Switching record locking off releases every lock, after a confirmation

### 🐛 Bug Fixes

- *(cache)* Keep Redis flush records for the largest store window in use
- *(cache)* Store no value read inside an open transaction
- *(cache)* Version counters degrade on memcached failures and never restart at 0
- *(soft-deletes)* The feature follows the model after a remove
- *(cache)* A smaller store window keeps a larger one recorded alive for its own window
- *(soft-deletes)* Purge the bin with the lock guard lifted
- *(cache)* Label an empty morphTo with the key name of its own target class
- *(cache)* The eviction policy check never degrades the index, and ignores a Redis without a memory limit
- *(cache)* A write memcached buffers is a served call for the version counters
- *(soft-deletes)* Purge a table no model reads in one statement, whatever its key
- *(soft-deletes)* Keep the bin of a model that pins soft deletes on in code
- *(jobs)* Retry the bin purge and the lock release
- *(jobs)* Never run two purges or two lock releases of one table at once

### 📚 Documentation

- *(soft-deletes)* Say what the remove migration purges at deploy

### 🎨 Styling

- *(core)* Type the search sync chunk closures and point at the renamed pest script

### 🧪 Testing

- *(core)* Rewrite the deferred tests through public behaviour
- *(dynamic-contents)* Restore the same-module mapping cases

### ⚙️ Miscellaneous Tasks

- *(cache)* Drop the cleanup of the flush hash no release shipped

## [1.82.0] - 2026-11-23

### 🚀 Features

- *(versioning)* Switching a table's versioning off purges its edit history

### 🐛 Bug Fixes

- *(versioning)* An empty versioning setting reads as off
- *(settings)* A stored false shows as its choice in the JSON setting select
- *(locking)* The optimistic lock command stores its setting row
- *(cache)* No value cached forever outside the label index
- *(cache)* A stats failure pauses counting for a minute, not for the process
- *(search)* Reindexing no longer flushes the CRUD cache of the model
- *(cache)* A lost database connection degrades a failover index too
- *(cache)* A flush Redis answers with an error degrades the index instead of vanishing
- *(cache)* A rolled back transaction flushes what it collected
- *(cache)* A deferred flush waits for the commit of its own connection
- *(cache)* Prune the legacy labelflush hash of the Redis index
- *(cache)* Dynamic content lists go through the label index
- *(cache)* Register a deferred flush on its own connection's transaction
- *(cache)* Flush the dynamic content lists after the commit of the write
- *(cache)* A flush a memcached store refuses degrades the index
- *(cache)* No modules table flag kept for good, the CRUD doc names the rollback flush

### 🚜 Refactor

- *(cache)* One remember helper for values cached through the label index

### 📚 Documentation

- *(versioning)* Switching versioning off and on
- *(cache)* The failover retry pacing is per process, the marker per host
- *(cache)* The label cache docs follow the follow-up fixes
- *(cache)* Store window agreement, uncounted refusals, script cost, primary clock
- *(cache)* The lost connection detector also matches some non-outages

### ⚡ Performance

- *(cache)* LabelledCache::forget() computes the key without reading the value

### 🧪 Testing

- *(versioning)* Switched back on, versioning anchors each record at its first save
- *(cache)* Label cache tests through public behaviour, with unconditional cleanup
- *(cache)* The flush outside a transaction, the scope reset, members on Redis
- *(crud)* A rolled back write misses the cached list once, as M4 flushes it

## [1.81.0] - 2026-11-19

### 🚀 Features

- *(cache)* Label index contract with in-memory and version-counter indexes
- *(cache)* Redis membership label index
- *(cache)* Label index selection, failover degradation, prune and stats commands
- *(crud)* Resolve cache labels of a CRUD read
- *(cache)* Flush labels on model events and pivot writes after commit
- *(crud)* Look up the response cache before computing a read
- *(cache)* Flush user, role and ACL labels on permission changes

### 🐛 Bug Fixes

- *(crud)* History reads the record its key names
- *(cache)* Order label flushes independently of the test clock
- *(cache)* Bounded Redis prune and flush, redis test group
- *(cache)* Shared outage marker, narrow outage exceptions, cheaper stats
- *(crud)* History labels without rowLabels, default eager loads, role keys parameter
- *(crud)* Label default withCount relations
- *(cache)* Flush immediately outside a transaction, keep legacy tag hooks
- *(cache)* Start a missing version counter on stores that do not increment one
- *(cache)* Flush grant scope labels on every pivot write, and inherited roles on ACL writes
- *(cache)* Close the final review findings on the label index
- *(cache)* Flush the tables Core writes without model events
- *(cache)* Keep row precision on CRUD API writes
- *(cache)* Flush the modifiable record on approval writes
- *(search)* Filter dates and nulls on Typesense

### 🚜 Refactor

- *(crud)* Split requested relation paths into display and membership
- *(cache)* Move remaining tag users to labels
- *(cache)* Share one active cron loader between warm and scheduler
- *(cache)* Scope the pending label set to the request, typed script replies

### 📚 Documentation

- *(cache)* Membership labels and the response cache
- *(cache)* Document recovery, degraded redis, stats buckets and limits
- *(cache)* One flush per transaction, the store window and the write path cost
- *(crud)* SearchHitsQuery describes XS5 before its parameters

### ⚡ Performance

- *(cache)* Flush the labels of a transaction once after its commit
- *(cache)* Flush Redis labels in one script, keep flush records for the store window
- *(cache)* Count label stats in one script on a Redis stats store

### 🎨 Styling

- *(core)* Declare the types pest type-coverage was missing

### 🧪 Testing

- *(core)* The media search answers on the generic search route, by POST and GET
- *(search)* Check the header the Elasticsearch client sends, not the handler stack's text
- *(cache)* Prove label flushes of clearByEntity, invalidateCache and morph pivots
- *(crud)* Response cache invalidation by entity on both index kinds
- *(crud)* Row-level invalidation of embedded records
- *(crud)* Add tests for modify route to ensure it rejects multiple keys
- *(core)* Purge check on batch seeder without a Redis server

## [1.80.0] - 2026-11-18

### 🚀 Features

- *(core)* Permissions per guard and guard-aware checks
- *(core)* Passkey login, social registration guard and per-method second factor
- *(core)* One authentication and one switch for every api route
- *(search)* Optional per-stage timings in the search meta
- *(core)* Introduce RelationFilter for nested ACL conditions on related records
- *(core)* Relation filters in ACLs
- *(search)* Strategy resolver contract with a cheap default
- *(search)* Resolve the search strategy per request
- *(core)* Related records obey their own permission and ACL
- *(core)* Every relation declares its return type
- *(core)* Relation sync works only within what the writer can read
- *(search)* Balanced mode, and a failing embedder no longer fails the search
- *(search)* Quality-driven retries
- Guest is empty on web and holds the public list on api
- Hide attributes from anonymous callers and project media on the api guard
- Preload the relations the appended attributes read, once per result set
- *(core)* Personal API tokens and service accounts
- *(core)* Edit pages refuse to overwrite a record that changed since the form was opened
- *(core)* System reads lift the per-row select check for every class
- *(search)* Cost and retry parameters with their cost controls
- *(search)* Report the fallbacks inside a search as degraded_reason
- *(core)* Cron jobs take parameters, placeholders and the scheduler flags from the table
- *(core)* Scope filter node, FilterScope attribute and ScopeGuard
- Declare the validity and activation scopes as filter scopes (scope filters task 6)
- *(core)* Store and validate scope filter nodes in ACLs (scope filters task 2)
- *(core)* Apply scope filter nodes in CRUD queries and ACL reads (scope filters task 3)
- *(core)* Accept client scope filter nodes in CRUD requests (scope filters task 4)
- *(crud)* Resolve translated and dynamic fields from a plain filter property
- *(core)* Leave scope filters out of search engines, enforce them at rehydration (scope filters task 5)
- *(filament)* Describe scope nodes in the ACL table and validate them in the ACL form
- *(crud)* Filter on attributes a model mounts from a related record
- *(core)* Let a writer that owns the order keep a new record's position
- *(core)* Let the user pick the backoffice language from the user menu
- *(core)* Attribute labels under app.fields, named like the database columns
- *(core)* Auth:reset-password command
- *(core)* Filament labels attributes from the owning module's fields keys
- *(core)* Resources named from entities keys, navigation groups by key
- *(core)* Backoffice strings from the app lang file
- *(core)* Filament pages load and save the translated fields they edit
- *(core)* Enhance user roles handling and service account management
- *(core)* Remaining backoffice strings, label sections documented
- *(core)* Backoffice content language apart from the interface; translation fixes
- *(core)* The default_language setting sets the default locale
- *(core)* Store modules in core_modules instead of a setting
- *(core)* Move the owner's updated_at when a part of it changes
- *(core)* Absorb the VersionStrategy enum (D14)
- *(core)* Make Version a Core model without the library base (D14)
- *(core)* Absorb the Versionable trait into HasVersions (D14)
- *(core)* Rename the history tables to core_versions* (D15)
- *(core)* Make the history tables append-only (D19)
- *(core)* Record trashed and restored change types (D20)
- *(core)* Add capture, baseline kind and SET NULL revert link (D52, D53, D58)
- *(core)* Apps the user can open in the profile response
- *(crud)* Aggregate error codes, limits and config
- *(crud)* ColumnKinds and aggregate test kit
- *(crud)* Time buckets, day truncation and aggregate periods
- *(crud)* AggregateQuery and AggregateRequest
- *(crud)* Aggregate engine totals and CrudService::aggregate
- *(crud)* Aggregate grouping by column and relation
- *(crud)* Decimal-safe roll-up of aggregate bucket partials
- *(crud)* Aggregate time buckets, periods and comparison
- *(crud)* Aggregate currency detection and mixed-currency warnings
- *(crud)* Aggregate route, limiter and error mapping
- *(crud)* Sort and select by a column of a to-one relation, and by mounted attributes
- *(core)* Laraplate brand icon as the default app logo, new app favicon setting
- *(core)* Welcome page shows the brand icons beside the module names, application version and description, no big logo
- *(search)* Keep the report context of a reranker failure in its warning
- *(core)* Rebuild the state at a revision from its nearest snapshot (C08, D61)
- *(core)* Store the creation set of a root as a baseline (D53, D60)
- *(core)* Never version comments, ratings and translations (D8, D10)
- *(core)* Tell an application transaction from the test harness's (D52)
- *(core)* Lock a root without dropping its relations or its lock version (D34)
- *(core)* One version set per root in one transaction (D43)
- *(core)* Record the capture quality of each version set (D47, D52)
- *(core)* Translation changes are rows of the owner's history (D10, D11, D28)
- *(core)* The save of a root with translations opens its revision scope (D28, D34)
- *(core)* A translation saved on its own opens its owner's scope (D52)
- Format and classify .env values
- Read and extend .env files atomically
- Add env:fill to align a .env with .env.example
- *(core)* A translation-only revision raises the owner's lock version once (D23)
- *(core)* WithinRevision() takes the options of its revision (D28)
- *(core)* Relation coverage and refusal of symmetric relations (D24, D57)
- *(core)* Membership rows from the events of the custom pivot (D41, D42)
- *(core)* Relation subclasses open the revision scope on both sides (D42, D52)
- *(core)* Validity, tab-count and media draft values are settings
- *(crud)* Implement atomic updates for records and relations in CrudService
- *(versioning)* Hard delete through the coordinator with one snapshot set (Slice 4 C1, C2)
- *(versioning)* Model:clear-expired purges through the coordinator (Slice 4 C3)
- *(core)* Style the embedded Horizon dashboard with the panel theme
- *(core)* Mark text shown in another locale with a grey suffix on the same line
- *(migrate-utils)* Unique index over live rows only
- *(core)* Vector profile contract and context
- *(versioning)* A whole-aggregate baseline after a best_effort set (D45, D52, D60)
- *(versioning)* Only an atomic baseline anchors (D47); baselines need a locked root; history leaves the baseline image out
- *(core)* Dates in the query become a filter
- *(core)* Request words and an unstemmed field in keyword search
- *(core)* Optional similarity floor carried by the vector profile
- *(core)* Models can declare an embedding context header
- *(core)* Scout:rebuild command
- *(core)* Update table filters layout and refactor rebuild index command confirmation
- *(versioning)* Schema epochs table and the epoch of each version row
- *(versioning)* Schema epoch of a root class, read at its first write per process
- *(versioning)* A schema epoch change makes a baseline due (D33)
- *(health)* Show the cache store and the search engine version on the Cache page
- *(welcome)* Trim the application card and lay the cards out as a masonry
- *(search)* A testing hack to try the Elasticsearch 9 client against a server of major 8
- *(core)* Plain text of an Editor field
- *(core)* Declare an indexed relation once for document and mapping
- *(core)* Host and extender contracts with a generic host implementation
- *(core)* Refuse a preset field that would shadow an extender column
- *(core)* Search an extender through the index of its host
- *(core)* With_extended opens a host list to the extended rows the user may read

### 🐛 Bug Fixes

- *(core)* Register no view path for a module without views
- *(core)* Repair guard-aware User::hasPermission and seeder default guard
- *(core)* Type the default guard fallback of the permission uniqueness rule
- *(core)* Self-authenticated api routes, token-only principals, 403 for authenticated denials
- *(core)* Throttle only failed bearer authentications per client address
- *(core)* Read a bearer claim the way Sanctum does and never resolve a token outside its checks
- *(core)* Relation filters fail closed on a list-form group and on an unsafe relation name
- *(core)* The filters cast refuses what it cannot read instead of skipping or guessing
- *(core)* The filters cast fails closed on unreadable columns and empty relation groups
- *(core)* The filters cast accepts only the exact keys of one node kind and refuses constraint-free groups
- *(core)* A read request runs only the model methods declared for the CRUD
- *(core)* Related reads fail closed outside the permission scheme, and group_by, facets and trees obey the related ACL
- *(core)* Pending modifications are read only by who may update or approve the record
- The history route needs update or approve and serializes the record through the model
- *(core)* Token expiry timezone, guest identity and atomic token creation
- *(core)* Final review fixes of the API access control
- *(core)* Parts read with their parent's permission on hydration; keep dynamic contents in history
- *(core)* A part hydrates with its own select or its owner's
- *(core)* A part hydrates with the select of any model along its chain
- *(core)* A Filament save writes the translated fields and the sort column that the form edits
- *(core)* Treat a delete with soft deletes switched off as a force delete after authorization
- *(core)* Refuse unknown filter nodes and after-query scopes (scope filters task 2 review)
- *(core)* Take the stored property filter hunks out of the scope filter commit
- *(core)* Gate client scope filters in the request parser (scope filters task 4 review)
- *(crud)* Apply a filter group as one nested condition (scope filters task 5 review)
- *(core)* Refuse scope filters that add no condition or reach rows through subqueries for clients
- *(core)* Drop the is_deleted toggle from the field form
- *(core)* Nest the list and relation filter groups, as applyFilters() does
- *(crud)* Constrain the translation locale on the base query
- *(core)* Send a valid link from the backoffice Reset Password action
- *(core)* Let auth:reset-password save a locked user
- *(core)* Read the default locale from app.default_locale, not app.locale
- *(core)* Lang:check-translations writes Pint-formatted lang files
- *(core)* Versions:compact refuses to run until compaction is redefined
- *(core)* A revert on a record locked by somebody else is refused
- *(core)* No generated permission on core_versions
- *(core)* A record deleted for good takes its polymorphic children along
- *(core)* A hard delete closes the pending modifications instead of removing them
- *(core)* Ensure controllers directory exists before loading files
- *(crud)* Tighten AggregateQuery and AggregateRequest validation
- *(crud)* Omit aggregate other when no group is left out
- *(crud)* Treat a blank currency like a null one in aggregate partials
- *(crud)* Fail loudly on an unreadable aggregate day
- *(crud)* Keep aggregate compare.series chronological
- *(crud)* A temporal cast on a numeric column is not a date column
- *(crud)* Sort the null aggregate group last on every driver
- *(crud)* Refuse an unreadable aggregate filter with UNREADABLE_FIELD
- *(crud)* Report aggregate deployment faults and log refused arguments
- *(crud)* Refuse an unsupported bucket driver before any query
- *(crud)* Report the aggregate refusals the action answers
- *(core)* Recognise the official modules by the getlaraplate/lara* package names
- *(core)* The Laraplate block of the Welcome page has no logo and no version
- *(core)* The blueprint icon set everywhere, no plate
- *(search)* Type the engine and the reranker keys of EnsembleSearchService
- *(core)* A soft delete is a DIFF of deleted_at whatever the strategy
- *(core)* The creation version is read back after the insert (C09)
- *(search)* Clear the PHPStan errors of the search services, jobs and engines
- *(core)* Keep the lock columns out of the version history (D21)
- *(search)* Type the Searchable trait for PHPStan
- *(media)* Clear the PHPStan errors of Media
- Env:fill writes through symlinks, keeps edits and owner, never leaks a warning, keeps example text
- *(core)* Media draft TTL of 0 keeps disabling pruning
- *(benchmark)* Measure boot up to the end of provider boot
- *(core)* The cache health page renders its widget again
- *(core)* Swagger leaves out redirect and view routes
- *(core)* The search engine health widget counts indexed documents
- *(core)* The search engine health widget counts records as the import does
- *(core)* Skip the self-read exemption for rows without a key
- *(core)* The backoffice falls back to any locale where the default one is missing too
- *(versioning)* Baseline policy reads the root by table; no baseline for a root whose row is gone
- *(core)* Fill toggles on hidden attributes from the record being edited
- *(versioning)* Epochs survive rollbacks and concurrent inserts; the anchor's epoch decides
- *(versioning)* Epochs per table, declared types, relation keys, root connection
- *(versioning)* Narrow the root key before locking it
- *(aggregate)* Refuse an amount whose currency the caller cannot read
- *(aggregate)* Refuse a relation filter node instead of applying it to the main record
- *(aggregate)* Know the Oracle and SQL Server names of numeric and date types
- *(aggregate)* Key the limiter by address for a user that is not a Core user
- *(aggregate)* Refuse a relation group on the entity's own table
- *(tests)* Give lightweight tests their own container before each test
- *(authorization)* Resolve a part's chain without reading the instance
- *(crud)* Read the filter operator in both spellings and refuse an unknown one
- *(core)* Follow wotz/laravel-swagger-ui v2 moving the spec pipeline off the controller
- *(core)* Register the route:list --internals option against Laravel 13
- *(core)* Resolve the supportsVectorSearch collision with Scout 11
- *(core)* Register the db:seed custom options against Laravel 13
- *(core)* Read Scout builder wheres through ScoutWheres for Scout 11
- *(core)* Make the Scout engine extend callbacks bindable
- *(core)* Call addCommand, which is the name Symfony Console 8 kept
- *(crud)* Refuse an unknown group operator and read filter operator names in any letter case
- *(acl)* Cast the operators of a stored filter to the syntax of the builder
- *(search)* Apply the compatibility header option to the client of ElasticsearchService too
- *(search)* Apply default search filters on every search path
- *(search)* Restrict the basic search path to the requested locale
- *(search)* Translate the typed sub-fields of an Elasticsearch object field
- *(approvals)* Hide a pending deletion from an anonymous HTTP visitor
- *(cache)* Tag cached CRUD responses so their invalidation reaches them
- *(core)* Extension filters and extended rows on every engine
- *(search)* Filter on a null value on Elasticsearch and Typesense
- *(search)* The Scout driver's Elasticsearch clients honour ELASTIC_COMPATIBLE_WITH
- The minors of the env:fill review (multibyte write, CRLF, secret names, cache failure, read-only check)

### 🚜 Refactor

- *(core)* The placeholder resolver of the ACL filters is a shared helper
- *(core)* Share the ACL scope walk between the save rule and the use-time check
- *(crud)* Match translated properties on the request locale only
- Seed the vector model under the openai_compatible provider
- *(core)* Read the vector profile instead of core.search.vector
- *(versioning)* Read the aggregate as rows once
- *(core)* Rename the engine capability to offersVectorSearch
- *(crud)* Name the operator conversions fromInput like the other enums
- *(search)* The service and the reindex job resolve the Elasticsearch client of the container

### 📚 Documentation

- *(search)* Search modes, degradation and retries
- API access control
- *(core)* API access control after the final review fixes
- Verbatim AGPL-3.0 licence text, copyright in the README
- *(crud)* Translated and dynamic field filters
- *(core)* Scope filters in ACLs, CRUD requests and search
- *(core)* Scope filters date parameter types, real 422 text, HasValidity README pointer
- *(core)* Scope filters that add no condition, client scopes without subqueries, review corrections
- *(crud)* Attributes mounted from a related record
- *(core)* Document the absorbed versioning (D14, D15, D19, D20)
- Point the links to the getlaraplate organization
- Fix a typo in the work in progress notice
- Use the Laraplate brand logo in the README
- *(crud)* Reflow the ColumnKinds class note
- *(crud)* Say a consumer aligns compare.series by key when series is not chronological
- *(crud)* Document the aggregate route
- *(crud)* Correct the aggregate route documentation
- *(core)* Document the reconstruction and the never-versioned models (Slice 2)
- *(core)* Document the version-set manager of Slice 3
- Document env:fill
- *(core)* Document the save scope and the translation history (Slice 4 Part A)
- *(core)* Draw the ACL and CRUD diagrams in Mermaid
- *(core)* Document plan-status, its modules and git dates
- *(core)* Complete the plan-status contract
- *(CrudController)* Clarify method injection for dispatcher in module action
- *(core)* Search pipeline names the AI Settings, not the removed env variables
- *(core)* Describe relation capture from pivot events (Slice 4 Part B)
- *(core)* Document the membership capture and the relation subclasses (Slice 4 Part B)
- *(versioning)* The delete wrapper, the hard-delete coordinator and the snapshot set
- *(core)* Vector search runs on a profile the AI module provides
- *(versioning)* When a whole-aggregate baseline is written and what it holds
- *(core)* Retrieval improvements
- *(core)* Scout:rebuild
- *(versioning)* Schema epochs, what the fingerprint covers and when workers restart
- *(aggregate)* Max_objects_per_message is read by the AI show_widget tool
- *(aggregate)* A mixed-currency amount ranks its groups by a hidden number
- *(aggregate)* Currency readability, relation nodes and ACL notes
- Require Laravel 13
- *(core)* Document the Scout 11 wheres contract the engines read
- *(rag)* A developer guide to read, list and restore the versions of a model
- *(core)* Front matter for the user documentation index
- *(core)* Evaluation dataset and report for the live user documentation index
- *(core)* Extended entity search and list

### 🧪 Testing

- *(core)* Check the soft delete through deleted_at, apply Pint
- *(core)* Give the soft delete probe settings table its managed column
- *(crud)* Dynamic fields of a model with only a components column
- *(core)* The MES machine ingest is no longer a self-authenticated route
- *(core)* The creation set of each root is its own baseline (D53, D60)
- *(core)* The default vector provider test holds when the AI module is installed
- *(aggregate)* Spring-forward periods and a rate test without a dead clear
- *(core)* Implement markEmailAsUnverified on the fallback user double
- *(core)* The extension registry is a shared, empty-at-start singleton

### ⚙️ Miscellaneous Tasks

- Point repository links to the getlaraplate organization
- Rename the Composer package to the getlaraplate vendor
- Align the package description with the repository description
- Align the module description with the repository description
- *(core)* Drop overtrue/laravel-versionable (D14)
- Call the organization CLA workflow
- Rename the repository and package from laraplate-<module> to lara<module>
- *(seeders)* Report every seeding step with one summary line
- *(seeders)* Benchmark the whole seeding run once
- Prefix perf:bench with test:
- *(core)* Drop the search.vector settings
- Drop the license-compliance-checker repository
- Keep the third-party licenses in NOTICES/
- *(deps)* Require Laravel 13 and its compatible package majors

## [1.79.0] - 2026-10-07

### 🚀 Features

- *(core)* One pending-approvals list for every entity, narrowed by permissions and ACL
- *(core)* A record origin's link must be a well-formed url
- *(core)* Approvals validate before capturing; votes reach pending creates without a record id
- *(search)* Measured retrieval tuning L1, query classes, tuning profile and extracted rank fusion
- *(search)* Restrict a search to the requested language inside the engine request
- *(search)* The reranker is off by default, and its default lives only in the seed
- *(search)* A reranker can name the model that scored, and the result records it
- *(settings)* A setting can be managed by a command and read-only in the form
- *(search)* Vector search is guarded: suspended, mismatching or disabled searches fall back to keywords with a reason
- *(search)* An index document carries the vectors of one embedding model
- *(settings)* A module can ask for a confirmation, or lock a setting, when its value changes
- *(search)* PostgreSQL stores vectors of any length, with one partial index per embedding profile
- *(core)* Generic interface labels for the Vue UI in app.php
- *(core)* Preferences are namespaced, merged per namespace, bounded and deletable
- *(search)* SearchQualityEvaluator, the retry judgement salvaged from IntelligentSearchAction

### 🐛 Bug Fixes

- *(core)* Approve governs disapprove
- *(core)* The modifications AI column shows the moderator's status
- *(command)* Update LockedAddCommand signature to use 'model:locked-add' instead of 'module:locked-add'
- *(search)* Make indexing fail loudly and reach every row
- *(approvals)* Apply a decision whenever votes reach the quorum
- *(approvals)* Deny generic CRUD writes on modifications
- *(approvals)* Keep quorum and author when a write is captured again
- *(core)* Make model:create-entity independent of the CMS module
- *(core)* Show automated moderation columns for any moderated model, not CMS comments
- *(core)* Resolve a preset's presettable from its own module
- *(core)* Sync entity_id from the presettable being set, without re-entering __get
- *(crud)* Reload the hits of a search under the user's ACL row filters
- *(search)* Rerank on the text a model provides, and fail when there is none
- *(settings)* Cover the managed form write path and narrow the reconciler JSON null to settings.value
- *(search)* Vector guard forget() is proven for several model classes and the key registry TTL is renewed
- *(settings)* Confirmed() only runs for a saved change that warn() still flags; move SettingChangeWarning to Data
- *(search)* Pgvector query uses a literal model filter, checks the index in the catalog first and guards dimensions
- *(core)* Stop the select guard from recursing while resolving the acting user
- *(core)* Merge every module lang directory per locale in the translations payload
- *(core)* Standalone validation and passwords locale files
- *(crud)* Facet aggregates valid under only_full_group_by
- *(crud)* Update validation excludes the record from its own unique checks
- *(core)* CrudService resolves the key of a form request that was never validated

### 💼 Other

- Make module-enumerating Core tests module-agnostic

Permission manifest, permission refresh, seed graph, capability scanner,
Filament plugin/trait resolution and runtime setting tests no longer name
CMS, AI, ERP, MES or SAO classes. They loop installed modules or use Core
stubs. Module-specific assertions moved into each module. The domain action
permission coverage check moved to the application integration suite.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016bQ9UWLJjnRQA6sGEYNGPL
- Resolve App-namespace entities in fetchAvailableEntities

The entity class was built by swapping the module segment, so an App type
resolved a nonexistent Modules\App\Models\Entity. It now uses the same
resolution as presets and presettables.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016bQ9UWLJjnRQA6sGEYNGPL
- Test dynamic entities with Core-owned App fixtures

Core tests no longer use CMS or ERP entities, presets and contents.
Fixtures live in stubs/App (App\\Casts\\EntityType, App\\Models\\Entity,
Preset, Pivot\\Presettable, Page, Author): Core resolves concrete dynamic
entity classes by module convention, and the App namespace is the one a
fixture outside a module resolves to. Adds a regression test for App
entity resolution in fetchAvailableEntities. The CMS presettable case
moved to CMS.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016bQ9UWLJjnRQA6sGEYNGPL

### 🚜 Refactor

- *(search)* Delete the orphaned SentenceTransformers embedding generator
- *(core)* Benchmark output goes to the console only, not to the log
- *(core)* The pre-processing events own their cache key and its lifetime

### 📚 Documentation

- *(core)* Describe the RestrictsCrudWrites guard on generic CRUD
- *(core)* RAG covers the write guard, the single approve permission and HasForm's gate
- *(core)* Graph materialized edges were measured and not built
- *(core)* Retrieval pipeline notes the tuner's overfitting safeguards
- *(search)* An IReranker that cannot score must throw, not answer
- Vector availability guard, pgvector partial indexes, managed settings and change confirmations
- *(core)* User preferences, user and developer guides
- *(core)* AI moderation is configured by settings, not AI_MODERATION_* env

### ⚡ Performance

- *(core)* Superadmin permissions from the registrar cache, cached modification tab counts; fix Horizon widget counts

### 🧪 Testing

- *(core)* Preset versioning follows the preset's connection
- *(core)* Required modules boot before their dependents
- *(core)* Only Core search creates and drops search indexes
- *(search)* A committed tuning profile must cite a validated report
- *(core)* Use Core fixtures instead of CMS models as sample models
- *(search)* Cover the once-per-key memo of the profile index
- *(core)* The vector model context test reads config through the same repository as the code, and the index ownership rule allows the embedding switch

## [1.78.0] - 2026-09-29

### 🚀 Features

- *(core)* Declare isImpersonated on User with an explicit return type
- *(models)* Add @mixin annotations for IDE helper support
- *(core)* Add content_hash to model_embeddings
- *(core)* Validate index mapping structure in scout:check-index
- *(core)* Additive scout:sync-mapping for new locale fields
- *(core)* List mapping incoherences and print ready-to-run fixes in scout:check-index
- *(core)* Add queue:monitor --all for every Horizon queue
- *(core)* Add the expiring validity scope the table filter was already calling
- *(core)* Assert supported Elasticsearch major version at the edge
- *(core)* Make the embeddings queue rate limit configurable
- *(core)* Add driver-agnostic AdaptiveBatchController (AIMD)
- *(core)* Let AdaptiveBatchController start at a configured batch size
- *(core)* Route bulk scout imports through adaptive batches
- *(search)* ProvidesDefaultSearchFilters hook on the generic Scout search
- *(migration)* Add content_hash column to model_embeddings table
- *(core)* Let locked users sign in and out
- *(core)* Dashboard system health with lightweight ping checks
- *(core)* Internal and public settings, edit-only settings and modifications resources
- *(core)* Is_internal follows the declaring module's ownership
- *(core)* Deletes, force deletes and restores go through approval
- *(core)* Approving a deletion or restore runs it, in one transaction with the vote
- *(core)* A pending deletion blocks the record, or hides it per model
- *(core)* Reload and clear-filters buttons on every HasTable list
- *(core)* Form pages say Close until something is unsaved
- *(core)* Authors withdraw their requests; decisions fire approved, rejected and withdrawn events
- *(core)* Imports index in deferred bulk flushes and stop cleanly on Ctrl+C
- *(core)* The CRUD API answers 202 for writes sent to approval
- *(core)* The panel reports captured writes and lets authors withdraw them
- *(modules)* Verify every declared module dependency is enabled, not just Core
- *(settings)* Add seeded-only action columns and exempt choices from approval
- *(seeding)* Realign setting actions and add command-managed choices definition
- *(settings)* Run a setting's action command with quoted placeholders
- *(settings)* Run a setting's action from the settings grid
- *(settings)* Flag and keep values no longer among the choices
- *(search)* Index model embeddings by content hash and embedding model
- *(search)* Media hits inherit their owner's visibility at rehydration (M16)
- *(search)* Owners carry their media's compact surrogate (media M9)
- *(readme)* Update logo size and add PHP version badge
- *(media)* Keep owner index and media vectors in step with the media lifecycle (M19)
- *(core)* Import search flushes go to the queue and report each chunk
- *(module)* Add QueueImportCommand for chunked model indexing and update ModuleDatabaseActivatorChoicesTest
- *(filament)* Resource-schema contributor seam (media analysis Task 13a, M22)
- *(core)* Pgsql connection that works with emulated prepares
- *(filament)* Read-only media gallery resource (media analysis Task 13c, M22)
- *(filament)* Media view renders contributed record actions (media analysis Task 13d, M22)
- *(filament)* Reusable media curation relation manager (media analysis Task 13b, M22)
- *(core)* A setting's value is validated against its type and choices

### 🐛 Bug Fixes

- *(search)* Scan the query by character, not by byte
- *(search)* Keep a domain write alive when the search engine is unreachable
- *(search)* Import the Queueable trait FinalizeReindexJob was missing
- *(filament)* Declare ISoftDeletableModel where SoftDeletes is used, not only inherited
- *(search)* Sanitize ES mapping at index creation so object/nested fields are valid
- *(core)* Drop obsolete lat_lon from geo_point mapping
- *(factory)* Update SettingFactory to append random number to name generation
- Repair what PHPStan found in the merged work, and tell the truth about testing
- *(core)* Drop the Expiring option from the validity table filter
- *(core)* ExpiredAt returns what had lapsed by that date
- *(core)* Fall back to the default timestamp column names in table filters
- *(core)* Four defects in the shared table and form utilities
- *(core)* A version was never attributed to anyone
- *(core)* Register SearchServiceProvider
- *(core)* Run the login listeners and end other sessions for licensed users
- *(core)* Seeders no longer realign a setting's group
- *(core)* Filament tables accept the Core user class, and the approval mocks say they are not superadmins
- *(core)* API votes honour the requests they name and never revote a decided one
- *(settings)* Re-apply the settings overlay before every queued job
- *(settings)* Skip the settings overlay for jobs on the sync queue

### 💼 Other

- *(core)* Settings follow the shared approval write rule; superadmin writes are never captured
- Add deterministic media metadata extraction on create

Task 2 of the media AI analysis plan (M2, M3a, M15). MetadataExtractor reads
embedded metadata per mime (IPTC/EXIF for images, getid3 for audio/video,
smalot/pdfparser for PDFs) into a MediaMetadata DTO, degrading to the content
hash on any malformed file. MediaMetadataService merges it into Spatie
custom_properties with a _provenance map so it never overwrites a human edit and
always refreshes content_hash + technical data; wired via a MediaMetadataObserver
on create. Adds getid3 and smalot/pdfparser (approved). Unit tests (6).

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Add generic searchable-contributor seam (M4a)

Task 4 of the media AI analysis plan. A Core-owned ISearchableContributor
contract + SearchableContributorRegistry (singleton, keyed by target model
class, subclass-aware) let another module add fields and mapping to a searchable
model's document without Core referencing it. Wired generically into the
Searchable trait (toSearchableArray merges contributor fields with base keys
winning; getSchemaDefinition adds contributor mapping) as a no-op until a
contributor registers. Unit tests + stubs; existing search behavior unchanged.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Make Media searchable and embeddable (M5)

Task 5 of the media AI analysis plan. Media now uses the Core Searchable trait
and implements IEmbeddableModel/ISearchableModel: a computed searchable_embed_text
accessor composes the custom_properties surrogate (description + keywords) with
AI-contributed text, and toSearchableArray ships facets (mime, track, collection,
keywords, description) from day one. Indexing is gated on claim via
shouldBeSearchable (draft-owned media stay out of the index). Extends the
contributor seam with embeddableText so AI text can join the standalone vector
without Core referencing AI. Feature tests (6).

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>

### 🚜 Refactor

- *(migrations)* Fold column/index/enum alters into their create migrations
- *(migrations)* Fold is_first_login/preferences into integrate_users_table
- *(models)* State locking, activation and validity as contracts
- *(models)* State soft deletion and search indexing as contracts
- Private methods for a final class, and instance calls where they are local
- Narrow findOrFail to a single record with whereKey()->firstOrFail()
- Give the domain action handlers and Filament pages their real types
- *(core)* Type what PHPStan read as an undefined property
- *(core)* Declare the type where it is lost, not the property where it exists
- *(core)* Say "the class using this trait" as self, and fix two contracts
- *(core)* Resolve the Builder scopes with contracts, not a rule
- *(core)* Ask the contract, not the trait, in the shared form and table
- *(core)* Type the approvals and validations concerns
- *(core)* Correct the captured-delete shape and type what HasVersions returns
- *(core)* Type what HasTranslations takes and returns
- *(core)* Read toggles moved to runtime settings from their setting keys
- *(core)* Module-prefixed setting keys, conservative defaults, AI moderation out of Core
- *(core)* Setting names drop the module prefix, the overlay adds it back
- *(core)* Domain-first setting names, gated licenses and modifications, Core group after Documentation
- *(core)* Use Str::studly for consistent label formatting in ListModifications and ListSettings
- *(core)* Update module group name and enhance module comparison logic
- *(core)* Crud settings live in the core group
- *(core)* The approval tables carry the Core prefix
- *(core)* Approval models stand alone, attributed to laravel-approval
- *(core)* HasApprovals carries the approval behaviour itself
- *(core)* Modifications carry an operation instead of is_update
- *(core)* Streamline backend module record creation and insertion

### 📚 Documentation

- *(rag)* Describe how the module is released from the application
- The dev toolchain is the application's, not this module's
- *(core)* Say what composer check does and does not answer
- *(core)* Attribute the locking code derived from MIT libraries
- *(core)* Attribute the approvals code derived from laravel-approval
- Approvals cover deletes and restores
- *(settings)* Setting actions and command-managed choices
- *(media)* Media search, owner visibility, surrogate and lifecycle
- Media gallery and curation Filament surface (media analysis Task 14)

### ⚡ Performance

- *(migrations)* Index all foreign-key and row-scoping columns
- *(core)* Eager-load relations once on the bulk indexing path
- *(core)* Honor toSearchableWith on the bulk indexing path

### 🧪 Testing

- Classify what UnitShell was holding, and say why five stay
- *(core)* Authenticate permission mocks without firing Login
- *(core)* Queue the version check in Elasticsearch tests and pin the embeddings default
- *(core)* The settings tests share the HTTP-context helper
- *(core)* The Core suite is green again
- *(core)* The withdraw panel test needs no permission on modifications

### ⚙️ Miscellaneous Tasks

- Rimuove docblock ide-helper generati dai model
- *(core)* Sort settings group tabs and hide the group column
- *(core)* Nothing references the laravel-approval package any more
- *(core)* Drop the laravel-approval requirement, the code is ours
- *(settings)* Drop translation provider settings now owned by AI

## [1.77.0] - 2026-09-15

### 🚀 Features

- *(embeddings)* Add locale and model_key provenance columns to model_embeddings
- *(search)* Translate locale-object analyzers and nested vector fields
- *(search)* Resolve nested embeddings.vector as the kNN field
- *(search)* Apply the full field mapping on index creation
- *(search)* Add shared locale->analyzer config map
- *(search)* Index all translated content, dropping LocaleScope from the import query
- *(search)* Match free text across per-locale title/body sub-fields
- *(embeddings)* Stamp locale and model_key on model embeddings
- *(search)* Per-locale embedding data + agnostic embeddings array
- *(search)* Add public getEmbedFields() accessor to Searchable trait
- *(events)* Add TranslationRequiresReembedding event
- *(search)* KNN over nested embeddings with document-level locale filter

### 🐛 Bug Fixes

- *(search)* Type makeAllSearchableUsing() param and return
- *(search)* Drop per-model schema-derivation from text-match field resolution
- *(search)* PrepareDataToEmbedByLocale must not use translation fallback
- *(import)* Keep record origin source timestamps timezone-safe

### 📚 Documentation

- *(core)* Say that settings cache invalidates per group
- *(search)* Document the retrieval pipeline and in-app notifications
- *(changelog)* Regenerate with the corrected git-cliff configuration
- Releases are run from the application

### 🎨 Styling

- Format with the application's Pint configuration
- Apply the module's own mb_str_functions rule

### ⚙️ Miscellaneous Tasks

- The module carries functionality, not the toolchain
- Core declares the runtime dependencies the application used to pin

## [1.76.0] - 2026-09-12

### 🚀 Features

- *(import)* Add a payload fingerprint for record origins
- *(search)* Enhance command handling with error logging and model filtering
- *(filament)* Own the module navigation group in the plugin
- *(core)* Add module color support and update widget descriptions
- *(seed)* Introduce 'pico' scale flag for dev seed volume adjustment
- *(import)* Refactor import command prompts to use Laravel\Prompts functions
- *(search)* Expose per-strategy ranked lists on AdvancedSearchResult.meta
- *(core)* Generate the lock verbs only where locking exists

### 🐛 Bug Fixes

- *(core)* Stop authorization from having to authorize itself
- *(table)* Enhance toggleable column with falseColor option and improve conditional alignment logic
- *(core)* Repair the ACL Filament resource and degrade indexing when the engine is down
- *(filament)* Render composite validity cells and sanitize form state
- *(core)* Make the per-table lock setting mean something
- *(search)* Survive rate-limit releases with retryUntil instead of dying on tries
- *(core)* Map Number field type to numeric validation rule
- *(search)* Scout:check-index delegates to the engine's checkIndex
- *(search)* Apply vector search on paginate(), not just search()

### 🚜 Refactor

- *(table)* Update validity and timestamp column formatting in HasTable trait

### 📚 Documentation

- *(core)* Correct the standard-stack env line

## [1.75.0] - 2026-09-09

### 🚀 Features

- *(core)* Let modules declare their own permissions and exclusions
- *(core)* [**breaking**] Make record locking mean something
- *(core)* Let a model say which attributes a lock does not cover
- *(core)* Enable search reranker by default (R3)

### 🐛 Bug Fixes

- *(core)* Register each module's views as an anonymous component path
- *(core)* [**breaking**] Name default-connection permissions `default.`
- *(core)* Stop answering 304 and 500 for client-side CRUD failures
- *(core)* Purge redis cache connection after seeder forks
- *(core)* Update laravel/boost dependency version to ^2.2
- *(core)* Gate application-content on the model table permission, not the short entity
- *(core)* Align vector index dimension to the embeddings provider; document vector enablement (R3)
- *(core)* Align vector dimension to 384 (real sentence-transformers output) across config, seeder, migration, engine, docs
- *(core)* Set embedding dense_vector dimensions from config (was translator default 1536)
- *(core)* Resolve model instance when createIndex is called by collection name (scout:index)
- *(core)* Apply the embedding dense_vector mapping to ES on index creation (ScoutDriverPlus creates a bare index)
- *(core)* Degrade to fused results when the reranker fails instead of breaking search
- *(core)* Pass a collection to the engine on indexing (IndexInSearchJob) so Scout update() works
- *(core)* Add public isEmbeddable() so external listeners can query embeddability without touching protected/private model internals
- *(search)* Recover indexing when the coordination event expired

### 🚜 Refactor

- *(core)* Let HasPlace own the place eager load
- *(core)* [**breaking**] Remove the deprecated grid surface
- *(core)* Update ProgressBarReporter to improve label handling and message formatting

### 📚 Documentation

- *(core)* Reference the real scout:* commands in the vector enablement runbook
- *(search)* Document late-retry indexing recovery on model_indexing cache expiry

### ⚡ Performance

- *(core)* Scope dynamic contents and entities to the request
- *(core)* Memoize the presettable fields snapshot hydration

### 🧪 Testing

- *(core)* Assert the seeded vector dimension is 384 (deterministic, seed-state independent)
- *(core)* Stub orchestrated-vector capability now that vector search is enabled by default
- *(core)* ES-gated integration test asserting createIndex applies the dense_vector embedding mapping

## [1.74.0] - 2026-09-01

### 🚀 Features

- *(core)* Hybrid approvals preview — request on app/api, session on admin
- *(core)* Coerce boolean inputs only on known boolean attributes
- *(approvals)* Expose active on Modification for pending signals
- *(approvals)* Expose modifier identity on Modification for viewer-relative pending
- *(versioning)* Implement restore functionality with conflict handling and report generation

### 🐛 Bug Fixes

- *(core)* Apply database settings per request and scope the resolver
- *(core)* Let MediaUploadRequest expose rules without a bound owner
- *(core)* Register locale and log-context middleware on app and api
- *(core)* Enable the CRUD API through the database setting the overlay reads
- *(core)* Stop appending path on every model serialization
- *(core)* Cache HasPlace relation and eager-load place on lists

### 🚜 Refactor

- *(core)* Enhance DynamicContentsService cache management and metadata handling
- *(core)* Deprecate the grid surface and run grid actions in-process
- *(core)* Scope permission memoization to the request instead of the process
- *(core)* Key the permission existence memo independently of the model class
- *(core)* Bound the closure-table depth cache to the request

### 📚 Documentation

- *(core)* Record process-level vs request-level cache rules

### 🧪 Testing

- *(core)* Guard against POSIX signal handlers in request-reachable code
- *(core)* Gate the class-independent permission memo through the production path
- *(core)* Warm the permission memo before pinning the seed query budget

### ⚙️ Miscellaneous Tasks

- *(core)* Enable the Spatie permission reset listener for long-lived workers

## [1.73.4] - 2026-08-26

### 🐛 Bug Fixes

- *(tests)* Expect six SQLite permission name triggers
- *(core)* Refuse committed swagger paths before generation

## [1.73.3] - 2026-08-26

### 🐛 Bug Fixes

- *(core)* Enhance BatchSeeder to reinitialize PermissionRegistrar cache

### 🚜 Refactor

- *(core)* Update BatchSeeder and DynamicContentsService for improved cache handling

## [1.73.1] - 2026-08-26

### 🐛 Bug Fixes

- *(core)* Enhance PermissionsRefreshCommand to handle stale model classes

## [1.73.0] - 2026-08-25

### 🚀 Features

- *(locking)* Expose lock columns in serialized models
- *(crud)* Add facet counters to CrudService and CrudController
- *(crud)* Constrain facet counts to ACL-visible rows
- *(core)* Add TabularPdfExporter tabular-to-PDF service
- *(core)* Own the media library table, model and config
- *(core)* Shared fingerprint normalization chain
- *(core)* Optional AI text generation request event
- *(core)* CRUD facet-counters tier 2 — open facets with key/label two-step
- *(core)* Facet tier 2 — single-hop relation labels
- *(core)* Facet tier 2 — label search/sort without a join
- *(crud)* Resolve facet labels via declared FK source without a BelongsTo
- *(crud)* Facet over a BelongsToMany/MorphToMany relation via its pivot
- *(crud)* Resolve relation-facet labels from a locale-scoped translation table
- *(crud)* Accept facet relation param + document faceted counts
- *(crud)* Translated labels for base-column FK facets
- *(crud)* Facet by a to-one relation column via join
- *(crud)* Fail fast when a facet groupBy/relation is unresolvable
- *(core)* Sync whitelisted m2m relations through the generic CRUD update
- *(core)* Authoring surface — app CRUD sees drafts, API stays valid-only
- *(core)* Dynamic placeholders in ACL filters
- *(core)* Role-scoped ACLs (acls.role_id)
- *(core)* Default list pagination to look-ahead + explicit mode discriminator
- *(core)* Self-service user preferences + first-login endpoints
- *(core)* Generic media HTTP API for any media-enabled entity
- *(core)* Media pending-bucket + claim, and row-level ACL on media
- *(core)* Optional module scope on login
- *(import)* Generic interactive bulk-import framework
- *(import)* SPA API for the interactive bulk import
- *(import)* Filament monitoring surface + shared launcher
- *(core/import)* Relation resolution by natural key
- *(core/import)* Surface relation metadata to the mapping UI
- *(core)* In-app notifications for finished imports

### 🐛 Bug Fixes

- *(core)* SQLite support for generated is_deleted/is_locked columns
- *(crud)* Exclude a relation facet own selection from its own counts
- *(crud)* Read relation-facet base labels on the query builder
- *(crud)* Make dotless relation-count aggregates work on prefixed-table entities
- *(crud)* Count records not groups for currentRecords when group_by is set
- *(acl)* Invalidate the resolver cache on ACL writes
- *(crud)* Return a full page instead of a single row for list pagination
- *(core)* Resolve the default presettable from the entity enum, not the table name
- *(core)* Invalidate dynamic-content metadata cache on model writes

### 🚜 Refactor

- *(core)* Extract reusable runParallelTasks helper from BatchSeeder
- *(core)* Resolve fingerprints through the shared chain
- *(core)* Drop the authoring-surface hack (superseded by role-scoped ACLs)
- *(core)* Unify media upload into one endpoint with server-minted token
- *(core)* Rename permissions.module column to module_name

### 📚 Documentation

- *(core)* Mark cross-cutting user guides for assistant module scope
- *(core)* Document shared tabular exporters as reusable primitives
- *(core)* Document the shared error fingerprint chain
- *(core)* Document the scoped-login contract in RAG
- *(import)* Document the interactive bulk-import framework
- *(import)* List the CMS and ERP importable entities
- *(core)* Document interactive import + notifications (repo + RAG)

### ⚡ Performance

- *(core)* Speed up dev seeding — skip versioning, cache preset fields
- *(core)* Short-circuit per-row select check on list, count facets in SQL
- *(core)* Optional list total via look-ahead pagination (totals flag)

### 🧪 Testing

- *(core)* Fix stale QueryBuilder signature and yaml-dependent swagger test
- *(core)* Stop the namespaced config() override from shadowing sibling commands

## [1.72.1] - 2026-08-07

### 🐛 Bug Fixes

- *(crud)* Normalize freshness timestamps and expose created/updated_at

## [1.71.0] - 2026-08-06

### 🚀 Features

- *(core)* Add syncVersioned for one-revision membership replacement
- *(perf)* Stress-test toolkit — perf:profile, perf:crud, perf:boot + auth profiling
- *(seeding)* Implement dev seeding with volume scaling options

### 🐛 Bug Fixes

- *(core)* Keep platform columns out of generated infolists too
- *(crud)* Gate inactivate on delete, activate on restore

### 📚 Documentation

- *(crud)* Clarify activate/inactivate require restore/delete respectively

### ⚡ Performance

- *(core)* Resolve entities via a basename index instead of a scan

## [1.70.0] - 2026-08-06

### 🚀 Features

- *(cache)* Implement custom CacheManager to support Core Repository
- *(versioning)* Mark rollbacks with a Revert version set
- *(core)* Add versioned relation descriptor and ownership enum
- *(core)* Declare versioned relations via HasVersionedRelations
- *(core)* Capture reference-relation membership as version rows
- *(core)* Reconstruct versioned relation membership from history
- *(docs)* Add user evaluation cases for search and permissions
- *(perf)* Add zero-dependency benchmarking harness (perf:bench)
- *(crud)* Add list freshness fingerprint endpoint
- *(crud)* Classify freshness check_ids as on_page, off_page, or gone

### 🐛 Bug Fixes

- *(tests)* Unskip dynamic contents and preset versioning suites
- *(core)* Make versioned relation attach idempotent
- *(seeders)* Keep table label on progress bar finish stats
- *(http)* Wrap list collections as JsonResource in ResponseBuilder
- *(grids)* Resolve grid action from route name instead of URL tail

### 🚜 Refactor

- *(tests)* Replace superadmin modifier with author in modification tests

### 📚 Documentation

- *(core)* Document versioned relation membership

### ⚡ Performance

- *(crud)* Drop redundant queries and cache model discovery

## [1.69.3] - 2026-08-05

### 🚀 Features

- *(grids)* Expose per-entity allowed operations in grid config
- *(versioning)* Implement soft and hard delete handling in versioning
- *(core)* Apply approve permission as write-time quorum credit
- *(core)* List pending approvals for entity inbox
- *(core)* Expose latest soft-kept disapproval for editor banner

### 🐛 Bug Fixes

- *(core)* Attribute shared-table settings to the table's owning module
- *(core)* Keep platform columns out of generated Filament resources
- *(schedule)* Use connection-affine schema check for cron loading

### 📚 Documentation

- *(core)* Document operations array in grid config endpoints

## [1.69.2] - 2026-08-04

### 🚀 Features

- *(import)* Pass optional console output to bulk importers

### 🐛 Bug Fixes

- *(core)* Keep Console app_path overrides inert until sandboxed
- *(i18n)* Restore flag mappings in locale files for consistency
- *(schedule)* Use live Schema::hasTable for cron job loading

## [1.69.1] - 2026-08-04

### 🐛 Bug Fixes

- *(seeding)* Deduplicate core setting definitions
- *(i18n)* Add FlagCDN country codes to locale lang files

### ⚙️ Miscellaneous Tasks

- *(models)* Update docblocks for SeedRun and User models to include IdeHelper mixins

## [1.69.0] - 2026-08-03

### 🚀 Features

- *(core)* Settings cleanup keyed on module state and drift
- *(core)* Track imported record fingerprints
- *(core)* Add the frontend UI translation keys to app.php (all locales)
- *(core)* Permission refresh as a graph node

### 🐛 Bug Fixes

- *(core)* Surface the rollback caveat to operators and cover the real db:seed wiring
- *(core)* Preserve connection affinity in approvals and stats
- *(core)* Preserve approval vote authorization
- *(core)* Restore corrupted translations from pre-2c7dec5 (pagination + sl validation)
- *(core)* Own derived settings by their module and harden cleanup
- *(core)* Complete translations + drop the runtime Italian merge
- *(core)* Normalize imported source timestamps
- *(core)* Reject guest content retrieval
- *(core)* Harden connection-scoped approvals and stats

### 📚 Documentation

- *(core)* Seeding orchestration and reconciliation
- *(core)* Document imported record identities

## [1.68.1] - 2026-08-01

### 🚀 Features

- *(core)* Seed run ledger
- *(core)* Seed orchestrator with per-node atomicity and in-run resume

### 🐛 Bug Fixes

- *(core)* Make the no-force-delete test actually reachable by reconciliation

### ⚡ Performance

- *(core)* Reconcile Core settings in one pass, drop duplicate model scan

## [1.68.0] - 2026-08-01

### 🚀 Features

- *(core)* SeedDefinition declaring structural vs initial fields
- *(core)* SeedReconciler with fixed query budget and drift-safe realignment
- *(core)* Expose current locale in response meta + user lang in user info

### 🐛 Bug Fixes

- *(core)* Validate identity value is not null or empty string
- *(core)* Make ModelCapabilityScanner's model skip observable

### ⚡ Performance

- *(core)* Single-pass model capability scan

### 🧪 Testing

- *(core)* Pin backfill's update-list scope and exercise restore/backfill at scale
- *(core)* Tie each capability flag to its own trait constant

## [1.67.0] - 2026-07-31

### 🚀 Features

- *(core)* Centralize permission name construction
- *(core)* Add domain action registry and contracts
- *(core)* Reject domain actions colliding with generic CRUD verbs
- *(core)* Add domain action dispatcher
- *(core)* Expose domain actions on the /app surface
- *(core)* Deterministic seeder dependency graph
- *(core)* Add RouteCheckCommand for resolving routes by URL
- *(core)* Seeder discovery with implicit module.json requires edges
- *(core)* Settings module ownership and seeded baseline columns

### 🐛 Bug Fixes

- *(core)* Repair lock and unlock CRUD operations
- *(core)* Stop test config override from crashing console commands
- *(core)* Align per-model setting names between writers and readers
- *(filament)* Stop HasForm from discarding the resource components
- *(core)* Stop dropping model observers registered during provider boot
- *(core)* Keep the optimistic locking column out of the versionable image

### 🚜 Refactor

- *(core)* Build permission names through PermissionName

### 📚 Documentation

- *(core)* Document the domain action surface

### 🧪 Testing

- *(core)* Cover CoreDatabaseSeeder behaviour instead of its source text
- *(core)* Assert dependsOn edges, not just sort position, in seeder graph builder

## [1.66.0] - 2026-07-30

### 🚀 Features

- *(core)* Enhance MigrateUtils to support explicit database connections
- *(core)* Implement optimistic locking with error handling
- *(filament)* Merge Filament --generate into HasTable/HasForm

### 🐛 Bug Fixes

- *(core)* Support legacy outbox job payloads
- *(core)* Preserve active connection in migration sql
- *(core)* Quote migration sql identifiers
- Preserve model connection affinity in database helpers
- *(core)* Close connection affinity guard bypasses

### 🧪 Testing

- *(core)* Enforce database connection affinity
- *(core)* Reject falsy connection names

## [1.65.0] - 2026-07-29

### 🚀 Features

- *(core)* Add environment indicator plugin for Filament with module version display
- *(filament)* Default Toggle fields to stacked label layout
- *(filament)* Implement HasForm Entity→Preset cascade
- *(filament)* Add make-resources command with generator rebinds

### 🐛 Bug Fixes

- *(core)* Bind outbox events to aggregate connection
- *(testing/core)* Move inline test classes to stubs for PSR-4 autoload
- *(core)* Map locale flags via lang + reduce stacked flag overlap

### 🚜 Refactor

- *(filament)* Wire HasForm into resource form schemas

## [1.64.1] - 2026-07-28

### 🐛 Bug Fixes

- *(core)* Preserve model-owned validation connections

## [1.64.0] - 2026-07-22

### 🚀 Features

- *(core)* Hide forced version strategy settings
- *(core)* Add abstract module import command
- *(core)* Add aggregate version sets
- *(core)* Support connection-aware import dry runs
- *(core)* Manage single connection version sets
- *(core)* Enhance validation rules handling and connection independence

### 🐛 Bug Fixes

- Scope core infrastructure database operations
- *(core)* Describe import dry-run connection
- *(core)* Harden version set persistence
- *(core)* Harden version set transaction state
- *(core)* Enforce version set state invariants
- *(core)* Finalize nested version set safety

### 🚜 Refactor

- *(core)* Centralize synchronous version writes

### 📚 Documentation

- *(core)* Document forced version settings
- *(core)* Document module import framework
- *(core)* Clarify importer connection affinity

### 🧪 Testing

- *(core)* Characterize version history writers
- *(core)* Isolate explicit version dispatch assertion

## [1.63.0] - 2026-07-21

### 🚀 Features

- *(graph)* Add authorized read-only tool gateway
- *(core)* Add application content provider registry
- *(core)* Authorize application content retrieval
- *(core)* Add transactional outbox

### 🐛 Bug Fixes

- Preserve model connections in core queries

### 📚 Documentation

- *(core)* Document transactional outbox
- *(core)* Document application content providers
- Add mixin annotations for Eloquent and IdeHelper in OutboxEvent model

### 🧪 Testing

- *(graph)* Move fixtures to PSR-4 stubs
- Guard model connection affinity
- *(core)* Isolate application content registry

## [1.62.0] - 2026-07-14

### 🚀 Features

- *(search)* Standardize database index migrations
- *(search)* Make matching profiles query adaptive
- *(search)* Support required terms and phrases
- *(core)* Add HasModuleTablesUtils trait and integrate with CoreTables enum

### 📚 Documentation

- *(search)* Explain explicit query syntax rationale

## [1.61.0] - 2026-07-14

### 🚀 Features

- *(search)* Add adaptive portable matching

### 📚 Documentation

- *(core)* Document graph system

## [1.60.3] - 2026-07-14

### 🧪 Testing

- *(tests)* Update graph traversal test to use 'core' namespace for relations

## [1.60.2] - 2026-07-14

### 🚜 Refactor

- *(tests)* Introduce stubs for graph traversal and enhance SQLite vector search tests

## [1.60.1] - 2026-07-13

### 🚜 Refactor

- *(core)* Optimize license handling and caching in dynamic content service

## [1.60.0] - 2026-07-13

### 🚀 Features

- *(core)* Support database orchestrated search
- *(core)* Normalize database vector search
- *(core)* Tighten database vector search parity
- *(core)* Add advanced search score and filter contracts
- *(core)* Enforce searchable filter metadata
- *(core)* Support indexed relation search filters

### 🐛 Bug Fixes

- *(core)* Harden model embeddings migration
- *(core)* Apply advanced filters to keyword search engines

## [1.59.0] - 2026-07-12

### 🚀 Features

- *(core)* Add graph provider registry
- *(core)* Add graph expand request
- *(core)* Add graph node serialization
- *(core)* Add graph relation inspector
- *(core)* Add graph traversal
- *(core)* Add graph expand service
- *(core)* Expose graph expand routes
- *(core)* Support morph graph traversal
- *(core)* Add graph search endpoint
- *(core)* Add graph stats endpoint
- *(core)* Add graph provider rules
- *(core)* Add materialized graph edge store
- *(core)* Add orchestrated scout search

### 🚜 Refactor

- *(core)* Update command descriptions, improve model checks, and enhance table widget functionality

### 🧪 Testing

- *(core)* Harden graph traversal metadata
- *(core)* Cover graph search expansion aggregation

### ◀️ Revert

- *(core)* Defer materialized graph edge store

## [1.58.8] - 2026-07-10

### 🐛 Bug Fixes

- *(core)* Encode array attributes before json validation rules

## [1.58.7] - 2026-07-10

### 🧪 Testing

- *(tests)* Add test-only models for PresetVersioningService coverage

## [1.58.6] - 2026-07-09

### 🚀 Features

- *(rules)* Add database guidelines and supported drivers to module context
- *(tests)* Add integration tests for module service providers and preset versioning service

### 🧪 Testing

- *(core)* Close >=60% coverage tier with behavioral tests

## [1.58.5] - 2026-07-09

### 🚀 Features

- *(core)* Enhance CRUD service and locking commands

### 🐛 Bug Fixes

- *(locking)* Update updateSettingsTable method to accept enabled parameter

## [1.58.4] - 2026-07-09

### ⚙️ Miscellaneous Tasks

- *(tests)* Add unit tests for extendable module models to ensure they remain non-final

## [1.58.3] - 2026-07-09

### 🐛 Bug Fixes

- *(core)* Keep exception context callback safe for all throwables

## [1.58.2] - 2026-07-09

### 🚀 Features

- *(core)* Unify validation errors and translation registration

## [1.58.1] - 2026-07-08

### 🚜 Refactor

- *(core)* Update CRUD operation constants and enhance validation context handling

## [1.58.0] - 2026-07-08

### 🚀 Features

- *(core)* Implement search mode functionality in CRUD operations

## [1.57.1] - 2026-07-07

### 🐛 Bug Fixes

- *(core)* Enhance locking commands and migration stubs

## [1.57.0] - 2026-07-07

### 🚀 Features

- *(core)* Add polymorphic record origins registry

## [1.56.2] - 2026-07-07

### 🧪 Testing

- *(core)* Handle non-array translation groups in TranslationsCheckCommand

### ⚙️ Miscellaneous Tasks

- *(core)* Normalize PHPDoc spacing in model docblocks

## [1.56.1] - 2026-07-01

### 🧪 Testing

- *(core)* Streamline database availability check and enhance benchmark tests

## [1.56.0] - 2026-06-30

### 🚀 Features

- *(core)* Filter internal command list

## [1.55.4] - 2026-06-30

### 🐛 Bug Fixes

- *(core)* Harden CRUD module resolution and inspector caching

## [1.55.3] - 2026-06-30

### 🚀 Features

- *(core)* Guard generic-CRUD writes on models that restrict them

### 💼 Other

- Add internal route list filter

## [1.55.2] - 2026-06-30

### 🚜 Refactor

- *(core)* Enhance PHPStan configuration and improve type handling

## [1.55.1] - 2026-06-28

### 🚜 Refactor

- *(core)* Enhance type declarations and method visibility across models and traits

## [1.55.0] - 2026-06-28

### 🚀 Features

- *(core)* Add tabular csv exporter

## [1.54.8] - 2026-06-27

### 🚜 Refactor

- *(core)* Remove redundant concern imports

## [1.54.7] - 2026-06-27

### 🚜 Refactor

- *(core)* Introduce domain exception hierarchy and auth exceptions

## [1.54.6] - 2026-06-26

### 🚜 Refactor

- *(core)* Move HasCrudOperations to Services/Crud/Concerns
- *(core)* Update trait namespaces and improve type declarations

### ⚙️ Miscellaneous Tasks

- *(core)* Release v1.54.5

## [1.54.5] - 2026-06-23

### 🐛 Bug Fixes

- *(migration)* Use heredoc for MySQL taxonomy parent triggers

### 🚜 Refactor

- *(core)* Move Eloquent traits from Helpers to Models/Concerns
- *(core)* Move command traits from Helpers to Console/Concerns
- *(core)* Move database traits from Helpers to Database concerns
- *(core)* Move HasCrudOperations to Services/Crud/Concerns

## [1.54.4] - 2026-06-11

### 🚜 Refactor

- *(swagger)* Enhance Swagger documentation handling and path resolution

## [1.54.3] - 2026-06-11

### 🐛 Bug Fixes

- *(swagger)* Prevent overwriting committed swagger assets during testing

### 📚 Documentation

- *(docs)* Update PHPStan configuration paths and add glossary documentation

### ⚙️ Miscellaneous Tasks

- *(swagger)* Adjust padding for route display in SwaggerGenerateCommand

## [1.54.2] - 2026-05-30

### 🐛 Bug Fixes

- *(database)* Add table existence check in DatabaseConfigOverlay

### 🧪 Testing

- *(settings)* Update runtime settings definitions and enhance test expectations

## [1.54.1] - 2026-05-28

### 🐛 Bug Fixes

- *(permissions)* Streamline permission handling in PermissionsRefreshCommand

## [1.54.0] - 2026-05-28

### 🚀 Features

- *(logging)* Enrich GELF metadata and add error fingerprint
- *(settings)* Implement runtime configuration syncing and enhance settings management

### 🚜 Refactor

- *(tests)* Enhance testing structure and add integration tests
- *(SearchEngineHealthTableWidget)* Improve error handling and index checking

## [1.53.2] - 2026-05-21

### 🚜 Refactor

- *(settings)* Unify settings retrieval and cache management
- *(settings)* Improve cache management for settings by group

## [1.53.1] - 2026-05-18

### 🐛 Bug Fixes

- *(command)* Enhance CreateEntityCommand with module resolution and entity type handling

## [1.53.0] - 2026-05-17

### 🚀 Features

- *(validity)* Implement user account validity checks and enhance moderation features

## [1.52.2] - 2026-05-15

### 🚜 Refactor

- *(requests)* Unify entity resolution across request classes

## [1.52.1] - 2026-05-15

### 🚀 Features

- *(core)* Enhance cache warming command and model structure

## [1.52.0] - 2026-05-15

### ⚙️ Miscellaneous Tasks

- *(deps)* Update package dependencies and improve type consistency

## [1.51.0] - 2026-05-09

### 🚀 Features

- *(concurrency)* Enhance batch processing with query count tracking
- *(helpers)* Add HasModuleTableName trait for dynamic table name generation
- *(helpers)* Replace HasModuleTableName with HasPrefixedTableName and enhance HasACL
- *(cache)* Implement in-memory caching for various components
- *(cache)* Introduce WarmCacheCommand for pre-warming critical cache entries

### 🐛 Bug Fixes

- *(core)* Prevent negative padding values in swagger generation and add validation helper

### 🚜 Refactor

- *(cache)* Enhance cache management and type safety
- *(console)* Update command descriptions to use green color coding

### 📚 Documentation

- *(module)* Enhance MODULE.md with detailed core functionalities and mermaid diagrams

### ⚙️ Miscellaneous Tasks

- *(rules)* Remove deprecated rule files and add module context

## [1.50.0] - 2026-05-04

### 🚀 Features

- *(locking)* Enhance locking commands with settings management

## [1.49.1] - 2026-05-01

### 🚜 Refactor

- *(translations)* Update pagination translations for multiple languages

## [1.49.0] - 2026-05-01

### 🚀 Features

- *(soft-deletes)* Implement SoftDeletes functionality with commands and traits

## [1.48.2] - 2026-05-01

### 🐛 Bug Fixes

- *(core)* Resolve UserFactory dynamic model without invalid property default
- *(User)* Update User model properties and remove duplicate Notifiable trait

### 🚜 Refactor

- *(core)* Enhance type safety and improve test configurations

## [1.48.1] - 2026-04-29

### 🐛 Bug Fixes

- Correct module naming and update test references

## [1.48.0] - 2026-04-29

### 🚀 Features

- *(core)* Introduce Field resource and related pages for dynamic content management

## [1.47.3] - 2026-04-28

### 🚜 Refactor

- *(core)* Extract parallel task runner from BatchSeeder
- *(core)* Rework License with bigint id and public uuid

### ⚙️ Miscellaneous Tasks

- Update testbench modules and enhance dynamic content handling
- *(core)* Cleanup leftover testbench gitlinks and stale comments

## [1.47.2] - 2026-04-24

### 🐛 Bug Fixes

- Enhance dynamic model class resolution and caching in DynamicContentsService

### 🚜 Refactor

- Improve model class handling and caching mechanisms

## [1.47.1] - 2026-04-23

### 🚜 Refactor

- Enhance model caching and relationship handling

## [1.47.0] - 2026-04-23

### 🚀 Features

- Add migrations for teams fields, user grid configs, presettables, and fieldables
- Implement Taxonomy and TaxonomyTranslation models with migrations

## [1.46.1] - 2026-04-18

### 🚜 Refactor

- Update Taxonomy model namespace and enhance validation rules

## [1.46.0] - 2026-04-18

### 🚀 Features

- Implement geocoding services and models for location management
- Add Taxonomy model for hierarchical content management

## [1.45.0] - 2026-04-17

### 🚀 Features

- Introduce CompactVersions command for version management

### 🚜 Refactor

- Remove unnecessary comments in ACL and Field models, and clean up migration for entity presets relation table

## [1.44.0] - 2026-04-16

### 🚀 Features

- Introduce ReadonlyModel trait and ReadOnlyModelException

### 🚜 Refactor

- *(core)* Merge presettables versioning into initial migration

### 🧪 Testing

- Add unit tests for version strategy and dynamic contents cache management

## [1.43.0] - 2026-04-16

### 🚀 Features

- Add CreateEntityCommand for dynamic entity creation
- Enhance CoreServiceProvider and EventServiceProvider for dynamic content management
- Enhance Entity and Preset models with abstract methods for dynamic content management
- Implement search service enhancements with dynamic query handling

### 🐛 Bug Fixes

- Correct return type annotations in HasDynamicContents trait

### 🚜 Refactor

- Update return type annotation and improve dynamic class resolution in Entity model
- Standardize model rules and enhance dynamic class resolution

## [1.42.0] - 2026-04-02

### 🚀 Features

- Introduce dynamic content management and field types

### ⚙️ Miscellaneous Tasks

- Update bootstrap path in phpunit.xml and enhance README for testing instructions

## [1.41.5] - 2026-04-01

### 🚜 Refactor

- Improve translation handling in Factory class

## [1.41.4] - 2026-04-01

### 🚀 Features

- *(core)* Harden query builder behavior and expand test coverage

### 🐛 Bug Fixes

- *(crud)* Correct composite PK in detail and skip relation methods in sort

### 🚜 Refactor

- Update module remapping and enhance author display in views
- Update translation handling in MakeModelTranslatableCommand and related tests

### 🧪 Testing

- *(core)* Expand Crud, QueryBuilder, and service coverage
- Expand coverage for models, observers, and services
- Expand coverage and stabilize password-related fixtures
- Expand unit and feature coverage across Core module
- Expand coverage and stabilize core command behaviors
- Expand ModelMakeCommand coverage and stabilize runtime branches
- *(core)* Reorganize suites and improve console command coverage

## [1.41.3] - 2026-03-24

### 🚜 Refactor

- Improve type hints and streamline code across various components

### ⚙️ Miscellaneous Tasks

- *(core)* Align ACL model, expand tests, and harden services

## [1.41.2] - 2026-03-14

### 🚜 Refactor

- Enhance caching logic and improve type hints across commands and requests

## [1.41.1] - 2026-03-13

### 🚀 Features

- Add core module rules for Cursor configuration
- Enhance table configurations and caching mechanisms across resources

### 🐛 Bug Fixes

- FreeAllLicenses, FreeExpiredLicenses and InitializeUsers command tests
- Improve cache invalidation logic and enhance validation handling

### 🚜 Refactor

- *(tests)* Remove unnecessary setAccessible calls in reflection tests

### 🧪 Testing

- Tests
- Fortify Actions coverage, HandleSocialLoginAction 100%, remove setAccessible, fix getClass deprecation
- Add unit tests for Auth, Services (AclResolver, ApprovalNotification, Authorization, Crud, DynamicEntity, Versioning)
- Add helper and search tests; type coverage 100%; fix TreeCollection null key
- Improve code coverage for classes near 100%

### ⚙️ Miscellaneous Tasks

- Type coverage al 98% e fix compatibilità override Laravel
- Update composer.json dependencies and improve command class type hints

## [1.41.0] - 2026-03-09

### 🚀 Features

- Add support for Yajra Laravel OCI8 extension

### 🐛 Bug Fixes

- *(crud)* Update 404, delete response, tree without id, and response builder guards
- *(core)* Harden benchmark and crud meta types

### 🚜 Refactor

- Update PHPUnit configuration and enhance command descriptions
- Streamline cache management and enhance model translatability
- Enhance command registration and improve test structure

### 🧪 Testing

- *(filament)* Refactor resource tests to config-only, no fake routes

### ⚙️ Miscellaneous Tasks

- Remove composer.lock and update .gitignore
- Sync working tree - casts, controller, requests, models, tests, config
- Ignore root build/ directory (PHPUnit coverage output)

## [1.40.0] - 2026-03-05

### 🚀 Features

- Add make:model-translatable artisan command

### 💼 Other

- Added composer.lock

### 🚜 Refactor

- Enhance tab functionality in ListModifications and ListSettings

### ⚙️ Miscellaneous Tasks

- Update license to AGPL-3.0 and enhance composer.json

## [1.39.0] - 2026-02-25

### 🚀 Features

- Add fetch duration measurement for table records and update pagination translations

## [1.38.0] - 2026-02-24

### 🚀 Features

- Introduce ModelMetadata and HelpersCache for improved model inspection and caching

### 💼 Other

- Set composer package type as laravel-module

## [1.37.0] - 2026-02-17

### 🚀 Features

- Introduce SchemaInspector for optimized database schema inspection

### ⚙️ Miscellaneous Tasks

- Add livewire/livewire dependency to composer.json

## [1.36.1] - 2026-01-30

### 🐛 Bug Fixes

- Standardize naming conventions for settings and improve table configurations

### ⚙️ Miscellaneous Tasks

- Update dependencies in composer.json

## [1.36.0] - 2026-01-30

### 🚀 Features

- Implement pending approval notifications and command

### 🚜 Refactor

- Update authentication method and clean up configuration files
- Simplify DocsController and introduce RouteServiceProvider override
- Replace PermissionChecker with AuthorizationService for permission handling
- Enhance ModificationsTable configuration and clean up HasTable trait

## [1.35.0] - 2026-01-22

### 🚜 Refactor

- Restructure Core module components and enhance documentation

## [1.34.0] - 2026-01-21

### 🚀 Features

- Introduce MoveEmbeddingTable command and ModelEmbedding model

## [1.33.2] - 2026-01-21

### 🚜 Refactor

- Deprecate AI configuration in README and update documentation
- Enhance CRUD helper and service for computed methods and relations

## [1.33.1] - 2026-01-15

### 🚜 Refactor

- Enhance ModuleDocGenerator to support abstract FormRequest classes

## [1.33.0] - 2026-01-15

### 🚜 Refactor

- Update command descriptions and remove deprecated files

## [1.32.1] - 2026-01-14

### 🚜 Refactor

- Enhance CRUD operations and introduce DTOs for improved data handling

## [1.32.0] - 2026-01-12

### 🚜 Refactor

- Update RecentActivityWidget and HasTable trait for improved functionality

## [1.31.0] - 2026-01-09

### 🚀 Features

- Add Swagger and Welcome pages for API documentation

### 🚜 Refactor

- Update permission handling and error management in search index functions
- Update user model references and enhance documentation structure

## [1.30.8] - 2025-12-22

### 🚜 Refactor

- Enhance BatchSeeder and Searchable trait functionality

## [1.30.7] - 2025-12-22

### ⚙️ Miscellaneous Tasks

- Update dependencies and version constraints in composer files

## [1.30.6] - 2025-12-22

### 🚜 Refactor

- Update Elasticsearch client configuration for improved clarity

## [1.30.4] - 2025-12-22

### 🚜 Refactor

- Update closures to static functions for improved performance and consistency
- Remove #[Override] attributes from getAttribute and setAttribute methods in HasTranslations trait
- Update Elasticsearch configuration and improve BatchSeeder logic

### 📚 Documentation

- Update README and implement PHP attributes for type safety

## [1.30.3] - 2025-12-19

### 🚜 Refactor

- Enhance error handling in BatchSeeder and update UserFactory for unique username generation
- Enhance translatable field handling in HasTranslations trait

### ⚙️ Miscellaneous Tasks

- Update cliff.toml to modify commit filtering settings

## [1.30.2] - 2025-12-19

### ⚙️ Miscellaneous Tasks

- Update cliff.toml and version.sh for improved commit parsing and version update handling

## [1.30.1] - 2025-12-19

### 🚜 Refactor

- Improve index creation logic and enhance Elasticsearch service tests

## [1.30.0] - 2025-12-17

### 🚜 Refactor

- Update CronJob and Permission factories, migrations, and tests for improved functionality and consistency
- Update Filament resource tests for improved structure and consistency
- Enhance BatchSeeder and translation traits for improved performance and clarity

### ⚙️ Miscellaneous Tasks

- Update Pest dependencies and improve README documentation
- Update PHP version requirement and refactor action classes for improved type safety

## [1.29.0] - 2025-12-13

### 🚀 Features

- Introduce custom CacheManager and enhance error handling in various components
- Add new actions for managing Swagger documentation, grid configurations, site settings, translations, and user information

### 🚜 Refactor

- Update user creation and query handling for improved consistency
- Clean up code structure and enhance readability across multiple files
- Update PHPStan configuration and enhance model traits with type annotations

## [1.28.1] - 2025-12-05

### 🚜 Refactor

- Enhance vector search capabilities in Elasticsearch and Typesense engines
- Improve batch seeding process with enhanced progress reporting and error handling

## [1.28.0] - 2025-12-03

### 🚜 Refactor

- Enhance batch seeding process with parallel execution and improved error handling

## [1.27.0] - 2025-12-02

### 🚜 Refactor

- Enhance translation services and clean up dependencies

## [1.26.0] - 2025-11-17

### 🚜 Refactor

- Streamline code structure and enhance memory management across various components
- Remove Compoships overrides and clean up composer dependencies
- Update database transaction handling and enhance batch processing
- Remove password hashing from user creation and updates

## [1.25.0] - 2025-10-21

### 🚜 Refactor

- Improve type hinting and exception handling in grid utilities and compoships
- Optimize memory management and code structure in console commands
- Optimize memory management and code structure across various components
- Enhance form and table utilities for improved functionality

### ⚙️ Miscellaneous Tasks

- Code lint with pint and rector

## [1.24.0] - 2025-10-14

### 🚀 Features

- Update composer dependencies and enhance project configuration

## [1.23.0] - 2025-10-13

### 🚀 Features

- Introduce SearchEngineHealthTableWidget and enhance CacheHealth page

## [1.22.0] - 2025-10-01

### 🚀 Features

- Add HorizonHealth page and corresponding view for Laravel Horizon integration

## [1.21.0] - 2025-09-30

### 🚀 Features

- Enhance search functionality and update dependencies

### 🐛 Bug Fixes

- Add check for up-to-date version in version.sh script
- Improve return handling in is_already_tagged function in version.sh
- Refactor return statements in determine_release_type function in version.sh
- Refactor determine_release_type function to use echo for version type determination
- Enhance version.sh script to determine commit importance and release type
- Clean up debug output and streamline commit analysis in version.sh

### ⚙️ Miscellaneous Tasks

- Update versioning mechanism in composer.json and enhance version.sh script

## [1.20.0] - 2025-09-27

### 🚀 Features

- Implement embedding table migration command and enhance search engine functionality

### 🚜 Refactor

- Enhance code structure and improve type declarations

### ⚙️ Miscellaneous Tasks

- Update changelog for version v1.19.2

## [1.19.2] - 2025-09-25

### 🐛 Bug Fixes

- Ensure URL protocol and handle embeddings count mismatch

## [1.19.1] - 2025-09-20

### 🐛 Bug Fixes

- Correct function name and add uncommitted changes check in version script

### 🚜 Refactor

- Introduce HasRecords and HasTable traits to streamline resource management

## [1.19.0] - 2025-09-19

### 🚜 Refactor

- Improve code consistency and enhance documentation
- Enhance Filament resource configurations and improve code structure
- Update BaseTable to enhance functionality and improve query handling

## [1.17.0] - 2025-08-19

### 🚜 Refactor

- Enhance resource configurations and improve column handling

## [1.16.1] - 2025-08-18

### 🚜 Refactor

- Improve code readability and structure across multiple files
- Enhance method validation and error handling in CrudHelper

## [1.16.0] - 2025-08-07

### 🚀 Features

- Enhance Swagger generation and CRUD API middleware
- Introduce Sentence Transformers integration for embedding generation

### ⚙️ Miscellaneous Tasks

- Update composer.json and enhance BatchSeeder functionality

## [1.15.1] - 2025-07-24

### 🐛 Bug Fixes

- Update git cliff command in version.sh for changelog generation

## [1.15.0] - 2025-07-24

### 🚀 Features

- Add new Filament resources for ACL, CronJob, License, Permission, Role, Setting, and User management

### 🐛 Bug Fixes

- Update post-commit hook script path for correct execution

### 💼 Other

- Update README.md with complete configuration documentation

### 🚜 Refactor

- Improve type safety and code clarity in ElasticsearchService
- Remove post-commit hook and enhance setup script
- Enhance code structure and type safety across multiple files
- Improve code clarity and type safety in multiple files
- Enhance type safety and clarity in PHPDoc comments across multiple files
- Update code formatting and improve type safety in multiple files
- Enhance type safety and code clarity in multiple files
- Enhance Elasticsearch and Typesense engine implementations for improved modularity and type safety
- Clean up PHPDoc comments in User model for improved clarity
- Update BatchSeeder and HasValidations for improved functionality and clarity
- Update module.json and improve code formatting across multiple files

### 📚 Documentation

- Update README.md with caution note and clarify project status

### ⚙️ Miscellaneous Tasks

- Add changelog and configuration for git-cliff
- Add configuration files for static analysis and code formatting
- Add versioning scripts to composer.json for improved version management

## [1.14.2] - 2025-05-05

### 🚜 Refactor

- Remove unnecessary comment in User model for improved code clarity
- Enhance type safety and code clarity across multiple files
- Fix method signature for improved type safety in ResponseBuilder
- Enhance type safety and code clarity in multiple classes

## [1.14.1] - 2025-05-05

### 🚀 Features

- Integrate Elasticsearch and Typesense for enhanced search capabilities

### 🚜 Refactor

- Improve null handling and code clarity across multiple files
- Enhance command structure and configuration management
- Update configuration management and clean up code
- Streamline code structure and enhance type safety
- Improve code clarity and consistency in various classes

## [1.14.0] - 2025-04-10

### 🚀 Features

- Enable model embedding generation for AI integration

## [1.13.1] - 2025-04-07

### 🚜 Refactor

- Enhance CommonMigrationFunctions and introduce CustomSoftDeletingScope

## [1.13.0] - 2025-04-06

### 🚀 Features

- Introduce BatchSeeder and DevCoreDatabaseSeeder for efficient data seeding

## [1.12.3] - 2025-04-06

### 🚜 Refactor

- Update PermissionsRefreshCommand and HasSeedersUtils for improved clarity and functionality

## [1.12.2] - 2025-04-05

### ⚙️ Miscellaneous Tasks

- Update PHP version requirement and refactor code for consistency

## [1.12.1] - 2025-03-31

### 🚀 Features

- Add AddRouteCommentsCommand for automatic route comment generation

## [1.12.0] - 2025-03-31

### 🚀 Features

- Enhance caching functionality and add route comments command

## [1.11.0] - 2025-03-28

### 🚜 Refactor

- Update command imports and enhance model creation logic

## [1.10.0] - 2025-03-20

### 🚀 Features

- Implement Elasticsearch job management

## [1.9.0] - 2025-03-11

### 🚀 Features

- Add command to clear expired soft-deleted models

## [1.8.1] - 2025-03-07

### 🚀 Features

- Enhance authentication configuration and CrudController

## [1.8.0] - 2025-03-07

### ⚙️ Miscellaneous Tasks

- Update project dependencies and configuration files

## [1.7.3] - 2025-03-03

### 🚜 Refactor

- Add type hints to User model relationships

## [1.7.2] - 2025-02-28

### 🚜 Refactor

- Modernize code with PHP 8.x comparison and method improvements

## [1.7.1] - 2025-02-28

### 🚜 Refactor

- Modernize PHP codebase with PHP 8.x features and code quality improvements

## [1.7.0] - 2025-02-28

### 🚀 Features

- Add LDAP support and enhance console commands

## [1.6.0] - 2025-02-26

### 🚀 Features

- Implement flexible authentication providers with LDAP and Socialite support

## [1.5.0] - 2025-02-21

### 🚜 Refactor

- Extract CRUD operations to a reusable trait and optimize Elasticsearch query generation

## [1.4.0] - 2025-02-19

### 🚜 Refactor

- Improve dependency injection and benchmarking across core components

## [1.3.0] - 2025-02-18

### 🚀 Features

- Enhance cache management and dependency injection

## [1.2.0] - 2025-02-16

### 🚀 Features

- Improve Grid processing and add benchmarking utility

## [1.1.1] - 2025-02-04

### 🚀 Features

- Improve user creation and error handling

## [1.1.0] - 2025-01-29

### 🚀 Features

- Improve Swagger documentation generation and configuration

## [1.0.0] - 2025-01-25

### 🚀 Features

- Enhance version management script with composer.json update

### 💼 Other

- Init project
- Add version and git hooks scripts to composer.json

<!-- generated by git-cliff -->
