---
module: core
audience: developer
---
# Record versioning: how to read, list and restore the versions of a model

A model that uses the `HasVersions` trait (`Modules\Core\Models\Concerns\HasVersions`) keeps a history of its
changes. This page answers the everyday questions: how do I get the versions of a record, how do I see what
changed, how do I go back to an earlier version, and how do I read the history over HTTP. How the history is
written (version sets, strategies, locking of the write path) is described in the "Versioning flow" section of
`MODULE.md`.

## Retrieve the versions of a model

Every versioned model exposes the history as relations on the record. They return `Core\Models\Version` rows.

```php
$post->versions;            // every version row of the record, all kinds, no particular order
$post->history;             // the changes, latest first, without the baseline image (use this for "what changed")
$post->latestVersions;      // latest first, baseline image included
$post->oldestVersions;      // oldest first
$post->latestVersion;       // only the most recent version (alias: lastVersion)
$post->firstVersion;        // only the oldest version
```

Each of them is also a relation method (`$post->history()`, `$post->latestVersion()`), so it can be filtered,
paginated or eager loaded: `Post::with('history')->get()`.

Which one to use:

| You want | Use |
|---|---|
| The list of changes to show to a person (audit trail, changelog, activity feed) | `history` |
| Everything stored, baseline rows included | `latestVersions` or `oldestVersions` |
| Only the last change | `latestVersion` |
| Only the first recorded state | `firstVersion` |
| The rows written when the record was soft deleted | `trashingVersions()` |

`history` differs from `latestVersions` only by the rows of the aggregate image that a `baseline` version set
appends after its own rows. That image restates the whole record (its translations and links): it is not a
change, so a changelog must not list it. The filter is the `Version::withoutBaselineImage()` scope.

## What a version row holds

`Core\Models\Version` is an append-only row: it has no `updated_at` and no soft delete.

| Attribute | Meaning |
|---|---|
| `contents` | the values written by this change: only the changed attributes with the `DIFF` strategy, the whole image with `SNAPSHOT` |
| `original_contents` | the values before the change |
| `change_type` | `VersionChangeType`: `created`, `updated`, `trashed`, `restored`, `deleted` |
| `user_id` | who made the change |
| `created_at` | when |
| `version_set_id`, `sequence` | the set (one operation) the row belongs to and its position |
| `version_strategy` | the strategy in force when the row was written |
| `relation_path`, `subject_key` | for a row that describes a translation or a relation link instead of the record itself |

Useful methods on a version:

```php
$version->versionSet;          // the version set: kind (change, revert, baseline), actor, capture quality
$version->previousVersions;    // the earlier versions of the same record
$version->nextVersion();       // the following version, or null
$version->stateAsOf();         // the attributes of the record as they were at this version
$version->revertWithoutSaving(); // the record with that version applied, not saved
$version->revert();            // apply and save
```

`stateAsOf()` throws `MissingVersionAnchorException` when no snapshot version exists to start the replay from.

## Restore a model to an earlier version

A revert targets a revision, the version set of a change, and takes back the revision the caller read:

```php
$expected = $post->currentRevision();

$report = $post->revertToRevision($revision, $expected);                      // complete: columns, translations, links
$report = $post->revertToRevision($revision, $expected, RevertMode::Scalar);  // the columns only
$report = $post->revertToRevision($revision, $expected, RevertMode::Link, force: true);
$report = $post->revertToVersion($version->id, $expected);                    // scalar, to the revision of that row
```

- `RevertMode` (`Modules\Core\Enums`): `Scalar` (the record's columns), `Link` (the membership of the registered
  `reference` relations), `OwnedPart` (the translations, by locale), `Complete` (all three, the default).
- `RevertReport`: `revision` (the `Revert` set written, null when nothing changed), `restoredRelations`,
  `skippedColumns` (columns the table no longer has), `skippedSubjects` (referenced records gone, skipped with
  `force`), `isPartial()`.
- A revert is a first-class operation: one version set of kind `Revert` that points at the target
  (`reverted_from_set_id`). History is never rewritten: a rollback is one more entry, and it can itself be reverted.
- Every check runs after the record is locked: `LockedModelException` when somebody else holds the record (see
  `RECORD_LOCKING_DEVELOPER.md`), `RevisionConflictException` when the record moved past the expected revision,
  `MissingRevertSubjectException` when a referenced record is gone and `force` is not set.
- `RevertRefusedException` names why a revert may not run (`$reason`): `not-versioned`,
  `not-a-revision-of-this-record`, `not-a-root-row` (`revertToVersion()` on a translation or link row),
  `no-complete-anchor`, and for a complete revert `incomplete-coverage`, `unsupported-relation`, `not-atomic`,
  `epoch-changed`. `RevisionReverter::completeRevertBlockers($record, $revision)` lists them without reverting.
- Details, and why each check exists: `MODULE.md`, *Reverting to a revision*.

## Turn versioning on for a model

1. Add the `HasVersions` trait to the model and declare `implements IVersionedModel`
   (`Modules\Core\Contracts\IVersionedModel`). A model extending `Modules\Core\Overrides\Model` already does both.
   The contract is how a translation, a part or a pivot finds out that the record it belongs to keeps a history: a
   model with the trait and without the contract has those changes left out of its versions. PHPStan reports the
   missing contract (`@phpstan-require-implements` on the trait), and the app test
   `VersionedModelsImplementTheContractTest` fails on it.
2. Choose the strategy. Either declare `protected $versionStrategy = VersionStrategy::DIFF;` on the model, or set
   the `versioning.strategy.{table}` setting (group `versioning`), which is read through a per-request map and a
   persistent cache. No value, `false` or an empty string means the model is not versioned. Some models are never versioned
   (`NeverVersionedModels`).
3. Limit what is recorded with `$versionable` (when not empty, only these attributes) or `$dontVersionable`
   (attributes never recorded; the default is `created_at`, `updated_at` and `last_login_at`). The optimistic
   locking column and the lock columns are always left out. Attributes listed in `$encryptedVersionable` are
   stored encrypted in the version row.

`VersionStrategy` is `Modules\Core\Enums\VersionStrategy`: `DIFF` stores only what changed, `SNAPSHOT` stores the
whole image on every change.

## Turn versioning off for a table

Setting `versioning.strategy.{table}` to `false` deletes the history of the table, by a queued job, except the
snapshots of records deleted for good. Switched back on, each record starts a new history at its next save. The
settings page asks for a confirmation first; the API does not. Details in `MODULE.md`, *Switching versioning off
and on*.

## Read the history over HTTP

The CRUD pipeline exposes the history of a record through the `history` route:

```
GET /api/v1/history/{module}/{entity}
```

It answers with the record and its history rows (`history` relation, latest first). Rules:

- The entity must have history handling, otherwise the call fails (`BadMethodCallException`).
- The caller needs `select` and `history` on the entity, or `revert`, which implies `history`
  (`RelationAuthorizer::mayReadHistory()`): the versions show who changed what and the past values, drafts
  included. A record the caller cannot see answers like a record that does not exist.
- `POST /api/v1/revert/{module}/{entity}` reverts a record over HTTP with `revert`; see `CRUD_SYSTEM.md`, *Revert Route*.
- `core_versions` has no generated permission: the history is only ever read through the record it belongs to.
- The record is serialized through the model, so hidden attributes and the media projection apply, and history
  rows carry no nested copy of the record.

## Limits to know

- The history is append-only. Compaction is not available: `purgeOldVersions` is accepted and ignored, and
  `versions:compact` refuses to run.
- Versions are written synchronously, inside the same transaction as the change, through the version set manager.
  A failure after the write rolls the history back with it.
- Deleting a record for good keeps a tombstone image; moving it to the bin writes `trashed` rows (see
  `trashingVersions()`), and a restore writes a `restored` row.
