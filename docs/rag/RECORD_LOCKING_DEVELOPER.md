# Record locking — developer and operator guide

## The model

Two columns carry two orthogonal axes. `locked_at` records when the current lock was taken and is
never refreshed. `locked_user_id` says whose it is. `locked_until` says when it lapses.

| `locked_user_id` | `locked_until` | Meaning | Icon |
|---|---|---|---|
| set | set | lease: only that user may edit, until that moment | padlock |
| set | NULL | hold: only that user may edit, no expiry (**requires `lock`**) | padlock |
| NULL | set | freeze until that moment: nobody may edit | snowflake |
| NULL | NULL | freeze with no end: nobody may edit | snowflake |

"Freeze" means **ownerless**, not permanent. Permanence is the absence of `locked_until`.

## Schema

Columns are generated in two places, and both must agree: the stub
`Modules/Core/app/Locking/Stubs/add_locked_column_to_table.stub`, and `MigrateUtils::locked()`,
which is the path model migrations actually take through
`MigrateUtils::timestamps(..., hasLocks: true)`.

| Column | Type | Notes |
|---|---|---|
| `locked_at` | `timestamp` nullable | when the current lock was taken; never refreshed |
| `locked_user_id` | `unsignedBigInteger` nullable | a `users.id`. **No foreign key**: a lockable model may live on a connection that does not carry the users table |
| `locked_until` | `timestamp` nullable, indexed | the deadline; null means none |

Column names come from config, so read them through `Locked::lockedAtColumn()`,
`lockedByColumn()`, `lockedUntilColumn()` rather than as literals.

**There is deliberately no `is_locked` column.** It used to be a stored generated column over
`locked_at`. It cannot survive a deadline: MySQL, PostgreSQL and SQLite all require a generated
column's expression to be deterministic, and expiry needs the current time. Keeping it as a plain
column maintained by the application would make correctness depend on the sweep. It is now an
appended accessor computed from `isLocked()`, so the attribute is still in every payload.

Ten models use `HasLocks`: `CMS\Content`, `Core\{CronJob, Taxonomy, User, Role, Entity}`,
`ERP\{SalesOrderLine, Quotation, Project, SalesOrder}`.

## Expiry is lazy

`isLocked()` is `locked_at IS NOT NULL AND (locked_until IS NULL OR locked_until > now())`, and the
`locked` / `unlocked` query scopes say the same thing in SQL. **A lapsed lock is free the moment it
lapses**, whether or not anything has tidied the row up.

`model:lock-sweep` clears the columns of lapsed locks. It is housekeeping and nothing depends on it:
it runs every five minutes with `onOneServer()`, clears at most `--limit` rows per model per pass
(default 1000), and deduplicates by connection and table because model discovery can reach the same
table through more than one class.

## Lock writes never go through `save()`

`HasLocks::writeLockColumns()` writes with a direct query-builder update: no model events, no
`updated_at`, no `lock_version`.

This is correctness, not efficiency. On a model that also uses `HasOptimisticLocking`, going through
`save()` reaches `performUpdate` and increments `lock_version` — the very version a client holds for
the form it has just opened, and the thing it compares to learn whether the record changed. Taking
the lock would destroy the signal it is measured by.

`setLockDeadline()` moves the deadline alone, leaving `locked_at` where it is: the client reads it to
say since when the record has been held.

## Semantics

`CrudService::doLockOperation` reads the intent from the request and picks the permission from the
**shape of the lock asked for**, not from who is asking. A user holding `lock` still only takes a
lease when it opens an edit form, because the form does not ask to freeze anything.

| Act | How it is asked for | Permission |
|---|---|---|
| Lease | the default | `update` on the record |
| Hold | `locked_until` explicitly null | `lock` |
| Freeze | `freeze: true` | `lock` |
| Release your own | the default | none |
| Release somebody else's | the default | `unlock`, plus its row-level ACL |

Rules that are easy to get wrong:

- **An anonymous caller may never take a lock.** Without an owner the write would produce a freeze,
  blocking the record for everybody. It is refused rather than degraded.
- **An implicit refresh on a valid lock of your own writes nothing.** The deadline is fixed at
  acquisition, not rolling, so the periodic re-lock is a pure read. An explicit `locked_until` is an
  assignment, and the lock is the caller's own to shorten.
- **Already in the target state is 200 with an empty `data`**, never 304. 304 answers a conditional
  request, carries no body by specification, and would land in the frontend's error branch since
  axios treats only 2xx as success.
- **Held by another, or frozen, is 423** with the holder and the deadline in the body. In bulk the
  record is skipped instead, and says so by not appearing in `affected_records`.

## The guard

`LockedModelSubscriber` is subscribed in `Core\Providers\EventServiceProvider::boot()` and listens on
`eloquent.saving`, `eloquent.deleting`, `eloquent.replicating`. It refuses a write on a locked record
unless the acting user is the holder; an ownerless lock matches nobody, which is exactly right.

`core.locking.prevent_modifications` is **on by default**
(runtime setting in group `locking`). A lock that
enforces nothing is decoration.

The guard has no acting user outside a request, so **on a queue or in the console nobody holds the
lock and every leased record is closed to writing**. That is the intended default: a lease protects
work in progress, and a background task overwriting it is the damage the mechanism exists to
prevent. System work that genuinely must go through says so out loud:

```php
Locked::withoutGuard(fn () => $invoice->recalculateTotals());
```

Nested calls restore the previous state rather than switching the guard back on halfway.

**A revert checks the lock of the whole record before it writes.** The guard sees one model's row, and a
revert also rewrites the record's translations and relations, which carry no lock of their own: a revert
that changed only a title would leave the row clean and walk past it. A revert (`RevisionReverter`, behind
`revertToRevision()` and `revertToVersion()`) therefore asks `HasLocks::blocksWritesByCurrentUser()` first, after the version set has reread the row
under `FOR UPDATE`, so a lock taken since the model was loaded counts too, and throws
`LockedModelException` before anything is written. The rule is the guard's: the holder of a lease may
revert, anybody else is refused, a freeze refuses everybody, and only a forced unlock (`forceUnlock()`,
behind the `unlock` permission) opens the record to others. `Locked::withoutGuard()` and
`core.locking.prevent_modifications` apply here as well.

There are no call sites in the codebase today. The bypass is for a caller that has a reason of its
own to overrule a lock; a model that is simply not fully locked says so on the model instead, below.

## Attributes a locked record still accepts

A lock answers one question, "who holds this record". It is binary and knows nothing about what any
column means. Some records nonetheless carry attributes that are not edits at all: a counter a
downstream process maintains, a status the system advances by itself. Refusing those stops the
system doing its work on a record somebody merely has open, which is not what a lock is for.

`HasLocks::attributesWritableWhileLocked()` returns an empty list, so a lock keeps meaning "no
writes" on every model until one states otherwise. A model that declares attributes accepts a write
only when **every** dirty attribute is on the list: one edited field makes the whole save an edit,
so a write that advances a counter and rewrites a price is refused on account of the price. Delete
is never partial and is refused regardless.

The distinction between the two mechanisms is *what* against *who*. The list is a property of the
model and holds for every caller. `withoutGuard()` is a property of one call site and holds for
whatever it writes.

`ERP\Models\SalesOrderLine` is the only model that declares any, and derives them so the lists
cannot drift:

```php
public function attributesWritableWhileLocked(): array
{
    return array_values(array_diff($this->getFillable(), self::LOCKED_COMMERCIAL_FIELDS));
}
```

A line belonging to a confirmed and therefore frozen document still has to be delivered, invoiced
and returned against, which is the very thing the document chain exists to record. The database had
already drawn that line, more finely than the guard could: the trigger on `erp_sales_order_lines`
blocks only the **commercial** fields of a locked line — `sales_order_id`, `quotation_item_id`,
`item_id`, `name`, `qty_ordered`, `unit_price` — and leaves the fulfilment quantities alone. The
model now says the same thing, once, and the ERP fulfilment services write through the guard with
no bypass.

The CMS importer is the counter-example. `ContentUpserter` writes `Content` from `cms:import` with no
authenticated user, so it meets the guard too, and it must **not** bypass: `ImportRunner` already
catches per row, so a held content becomes a reported skip and the run continues, which is the
protection working rather than failing.

## Database triggers (ERP only)

`Modules/ERP/database/migrations/2026_05_07_200000_create_lock_guard_triggers.php` installs, on MySQL
and PostgreSQL, guards over `erp_quotations`, `erp_sales_orders`, `erp_projects` and
`erp_sales_order_lines`. They block **frozen** rows only, through a shared predicate:

```sql
OLD.locked_at IS NOT NULL
  AND OLD.locked_user_id IS NULL
  AND (OLD.locked_until IS NULL OR OLD.locked_until > CURRENT_TIMESTAMP)
```

A trigger cannot know who is writing, so it must not block on the mere presence of a lock: it would
make the four ERP documents the only lockable models on which a user cannot edit the record they took
charge of. The chain triggers that lock documents on confirmation write `locked_at` and leave
`locked_user_id` null, so everything ERP locks by itself stays immutable at the database level.

The expiry clause is there because a trigger cannot benefit from lazy expiry and would otherwise keep
rejecting writes against a freeze that is already over.

These are a no-op on SQLite, which is what the suite runs on, so the regression guard is
`Modules/ERP/tests/Integration/Locking/LockGuardTriggersTest.php`, which asserts over the emitted
DDL.

## Optimistic locking

`HasOptimisticLocking` is separate and complementary: `lock_version` is bumped on every update and
`performUpdate` adds `where lock_version = N`. Used by `CMS\Content` and `SAO\Ticket`.

The version a client holds is applied by `CrudService::update`, and **only when the request names a
single record**: a version belongs to one row that one client read, and applying it to every row a
criteria matched would either fail at random or, worse, pass and overwrite rows the client never saw.
It used to be read off the global request inside a model event, which did exactly that, and also ran
on queues and in the console where no such request exists.

| Exception | Status | Meaning |
|---|---|---|
| `StaleModelLockingException` | 409 | somebody wrote between the client's read and its write |
| `MissingLockVersionException` | 400 | the version was not sent, or a partial select omitted it |
| `LockedModelException` | 423 | somebody else holds the record, or it is frozen |
| `CannotUnlockException` | 403 | this class is configured as unlockable by nobody |

## Filament

`Modules/Core/app/Filament/Utils/HasRecordLease` takes the lease on `afterFill`, the hook
`EditRecord::fillFormWithDataAndCallHooks()` calls once the record is resolved. Opening the edit page
is a statement of intent, since viewing has its own page. Nothing releases the lease: the deadline
does, and reopening takes a fresh one.

When the record is held the page asks rather than deciding: a modal that cannot be dismissed by
clicking away offers "back to the list" or "open read-only", and read-only disables the whole schema.
Applied to the eight edit pages of lockable models.

Freeze and unfreeze are row and bulk actions in `HasTable`, gated by `lock` and `unlock`. Tables
never offer a lease.

## Configuration

| Key | Env | Default | Meaning |
|---|---|---|---|
| `core.locking.lock_version_column` | `LOCKIN_LOCK_VERSION_COLUMN` | `lock_version` | optimistic version column |
| `core.locking.lock_at_column` | `LOCKIN_LOCK_AT_COLUMN` | `locked_at` | when the lock was taken |
| `core.locking.lock_by_column` | `LOCKIN_LOCK_BY_COLUMN` | `locked_user_id` | who holds it |
| `core.locking.lock_until_column` | `LOCKIN_LOCK_UNTIL_COLUMN` | `locked_until` | when it lapses |
| `core.locking.lease_ttl` | `LOCKING_LEASE_TTL` | `900` | lease lifetime in seconds |
| `core.locking.unlock_allowed` | runtime setting | `true` | whether locks may be lifted at all |
| `core.locking.can_be_unlocked` | `LOCKING_CAN_BE_UNLOCKED` | empty | classes exempt when the above is false |
| `core.locking.prevent_modifications` | runtime setting | `true` | whether the guard refuses writes |

## Commands

| Command | Purpose |
|---|---|
| `model:lock-refresh` | detects drift between models using `HasLocks` and the columns actually present, and generates the migrations to close it |
| `model:lock-sweep {--limit=1000}` | clears lapsed locks. Housekeeping: a missed run changes nothing |
| `model:locked-add {model} {--namespace=}` | adds the lock columns to a model's table and switches its `locking.enabled.{table}` setting on |
| `model:locked-remove {model} {--namespace=}` | removes them, after releasing every lock of the table (see below) |
| `model:optimistic-lock-add {model} {--namespace=}` | adds `lock_version` and switches its `locking.optimistic.{table}` setting on |
| `model:optimistic-lock-remove {model} {--namespace=}` | removes it |

**Switching locking off releases the locks.** Switching record locking off or removing it deletes no record: it
releases every lock of the table, so no record stays held by a lock nothing enforces any more (or that a worker still
running the class with the trait would keep enforcing). `Locking\LockedRowsReleaser` clears the columns `HasLocks`
writes (`Locked::lockedAtColumn()`, `lockedByColumn()`, `lockedUntilColumn()`) on every row with a `locked_at`, lapsed
locks included, in one statement, and flushes the whole table from the cache after it (`flushTable()`), since the
write fires no model event. A save that moves `locking.enabled.{table}` from on to off (the settings page, the API, an
approved modification) makes `SettingObserver` queue `ReleaseLocksJob` after the commit; the job reads the setting
again and does nothing when locking is back on. The settings page asks first (`LockingSettingConfirmation`, with the
number of locked records), and cancelling saves nothing. `model:locked-remove` asks before it changes anything when the
table has locked records: a refusal leaves locks, migrations and setting as they are; confirmed, it releases the locks
at once, then writes the migration and deletes the setting. Optimistic locking (`lock_version`) holds no lock: its
setting and commands ask nothing and release nothing. Pinned in `tests/Integration/Locking/LockingSwitchTest.php`.

## Permissions

`lock` and `unlock` are both `ActionEnum` cases, so `permission:refresh` generates them and
`PermissionRefreshSeeder` covers new installs. An existing installation needs one
`php artisan permission:refresh`, because `resolvePermission` uses `firstOrFail` and a missing row
throws rather than denying.

**No default ACL ships for `unlock`.** Both candidates grant nothing: "only locks you imposed" is
degenerate because releasing your own lock never passes through `unlock`, and `locked_until < @now`
is degenerate because a lapsed lock is already free to everybody and the write path returns before
the ACL is consulted. Granting `unlock` means granting the right to unblock people; a deployment that
wants less writes its own ACL, and `@user.<attribute>` placeholders are available for it.

## Testing

Locking behaviour cannot be proven by round-tripping values on SQLite, which ignores declared column
types. Assert types through the schema builder. The suites:

- `Modules/Core/tests/Integration/Locking/` — expiry, scopes, write isolation, the guard, the bypass
- `Modules/Core/tests/Feature/Controllers/CrudLockActionTest.php` — the whole HTTP contract
- `Modules/Core/tests/Feature/Filament/EditPageLeaseTest.php` — the panel lease
- `Modules/Core/tests/Integration/Filament/Utils/LockTableActionsTest.php` — freeze/unfreeze gating
- `Modules/ERP/tests/Integration/Locking/LockGuardTriggersTest.php` — the trigger DDL
- `Modules/SAO/tests/Feature/Http/TicketOptimisticLockingTest.php` — 409 and 400 end to end
