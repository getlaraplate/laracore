<?php

declare(strict_types=1);

namespace Modules\Core\Models\Concerns;

use function property_exists;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
// use Thiagoprz\CompositeKey\HasCompositeKey;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Arr;
use Modules\Core\Contracts\ILockableModel;
use Modules\Core\Contracts\IOptimisticLockableModel;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Locking\Exceptions\LockedModelException;
use Modules\Core\Models\Version;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;
use Modules\Core\Versioning\Contracts\VersionWriterInterface;
use Modules\Core\Versioning\Data\VersionChange;
use Modules\Core\Versioning\Data\VersionSetOptions;
use Modules\Core\Versioning\Data\VersionSetRoot;
use Modules\Core\Versioning\NeverVersionedModels;

// use Illuminate\Database\Eloquent\Relations\Pivot;
// use Illuminate\Database\Eloquent\Relations\MorphMany;
// use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The history of a model: its version rows, written by Core's writer from the model events.
 *
 * Derived from overtrue/laravel-versionable (MIT), see LICENSES/laravel-versionable.md: the versioning
 * switch, the version relations and the attribute helpers were the package's.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-type HasVersionsType HasVersions
 */
trait HasVersions
{
    protected static bool $versioning = true;

    protected ?User $_creator;

    protected ?User $_modifier;

    // protected VersionStrategy $versionStrategy = VersionStrategy::DIFF;

    /**
     * @var list<string>
     */
    protected array $dontVersionable = ['created_at', 'updated_at'/* , 'deleted_at' */, 'last_login_at'];

    protected bool $asyncVersioning = true;

    /**
     * Attribute names whose value is stored encrypted in the version row. A list
     * of column names, which is what VersionChange::encryptSelected() expects.
     *
     * @var list<string>
     */
    protected array $encryptedVersionable = [];

    /**
     * @var array{strategy: VersionStrategy, keys: list<string>, original: array<string, mixed>, type: VersionChangeType}|null
     */
    private ?array $pending_version_update = null;

    /**
     * The delete captured on `deleting`, written on `deleted`. A hard delete keeps
     * the whole image, a SNAPSHOT tombstone; a soft delete records the one column
     * that moved, always as a DIFF whatever the model's strategy, since a row
     * labelled SNAPSHOT is taken for a complete image by the reconstruction (C08).
     *
     * @var array{mode: 'hard', original: array<string, mixed>}|array{mode: 'soft', deleted_at_column: string, original: array<string, mixed>}|null
     */
    private ?array $pending_version_delete = null;

    /**
     * L1 in-memory cache for version strategy resolution.
     * Keyed by model class name, value is the resolved VersionStrategy or false (disabled).
     * Eliminates repeated deserialization of the persistent cache collection on every call.
     *
     * @var array<class-string, VersionStrategy|false>
     */
    private static array $version_strategy_cache = [];

    /**
     * Reset the L1 version strategy cache.
     * Used in tests and long-running processes to clear stale state.
     */
    public static function resetVersionStrategyCache(): void
    {
        self::$version_strategy_cache = [];
    }

    public static function getVersioning(): bool
    {
        return static::$versioning;
    }

    public static function disableVersioning(): void
    {
        static::$versioning = false;
    }

    public static function enableVersioning(): void
    {
        static::$versioning = true;
    }

    /**
     * Saves the model, inside the revision scope of its root when the save writes more than the root's own row: a
     * versioned root that owns translations or registers relations opens its scope before its SQL, so the root, its
     * pending translations and their history are one revision committed together (D28, D34). Any other save, and a
     * save with nothing to write, runs as Eloquent's.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if (! $this->opensRevisionScopeOnSave()) {
            return parent::save($options);
        }

        $manager = app(VersionSetManagerInterface::class);

        // Inside an open scope the save joins it as it is (a revert, an explicit revision): options of its own
        // would be refused as a different operation.
        return (bool) $manager->run(
            VersionSetRoot::forModel($this),
            fn (): bool => parent::save($options),
            $manager->current() === null ? new VersionSetOptions(actor: $this->getVersionUserId()) : null,
        );
    }

    /**
     * @return MorphMany<Version, $this>
     */
    public function versions(): MorphMany
    {
        return $this->morphMany($this->getVersionModel(), 'versionable');
    }

    /**
     * The versions, latest first. Declared with its relation type: a relation a request names is called only
     * when its declaration says it returns a relation.
     *
     * @return MorphMany<Version, $this>
     */
    public function history(): MorphMany
    {
        return $this->latestVersions();
    }

    /**
     * @return MorphMany<Version, $this>
     */
    public function latestVersions(): MorphMany
    {
        return $this->versions()->orderLatestFirst();
    }

    /**
     * @return MorphMany<Version, $this>
     */
    public function oldestVersions(): MorphMany
    {
        return $this->versions()->orderOldestFirst();
    }

    /**
     * @return MorphOne<Version, $this>
     */
    public function latestVersion(): MorphOne
    {
        return $this->morphOne($this->getVersionModel(), 'versionable')->orderLatestFirst();
    }

    /**
     * @return MorphOne<Version, $this>
     */
    public function lastVersion(): MorphOne
    {
        return $this->latestVersion();
    }

    /**
     * @return MorphOne<Version, $this>
     */
    public function firstVersion(): MorphOne
    {
        return $this->morphOne($this->getVersionModel(), 'versionable')->orderOldestFirst();
    }

    /**
     * @return list<string>
     */
    public function getVersionable(): array
    {
        return property_exists($this, 'versionable') ? $this->versionable : [];
    }

    public function getRefreshedModel(Model $model): Model
    {
        return $model->newQueryWithoutScopes()->findOrFail($model->getKey());
    }

    /**
     * @return class-string<Version>
     */
    public function getVersionModel(): string
    {
        return $this->versionModel ?? config('versionable.version_model', Version::class);
    }

    public function getUserForeignKeyName(): string
    {
        return $this->userForeignKeyName ?? config('versionable.user_foreign_key', 'user_id');
    }

    /**
     * The optimistic locking column is concurrency plumbing, not model state, and so are the lock columns.
     * Leaving it in a SNAPSHOT image would make a restore write back a stale
     * token, and every later update on that record would fail as if another
     * user had touched it.
     *
     * @return list<string>
     */
    public function getDontVersionable(): array
    {
        $columns = $this->dontVersionable;

        // Only models that version their writes carry the column, and the contract
        // is what says so. Called through $this rather than static: the narrowing
        // applies to the instance, and static:: would be read as the bare trait
        // user again.
        if ($this instanceof IOptimisticLockableModel) {
            $columns[] = $this::lockVersionColumn();
        }

        // The lock columns are coordination state, written without events: in a snapshot they would let a
        // revert bring back a stale lock or drop a live one (D21).
        if ($this instanceof ILockableModel) {
            array_push($columns, $this->getLockedAtColumn(), $this->getLockedByColumn(), $this->getLockedUntilColumn());
        }

        return array_values(array_unique($columns));
    }

    public function shouldBeVersioning(): bool
    {
        $version_strategy = $this->getVersionStrategy();

        if ($version_strategy === false) {
            return false;
        }

        // Three ERP models declare their own rule; the method is protected, so it
        // cannot live on a contract and method_exists is what asks for it.
        if (method_exists($this, 'shouldVersioning')) {
            return (bool) $this->shouldVersioning();
        }

        $versionableAttributes = $this->getVersionableAttributes($version_strategy);

        // no need to count already existent versions
        return Arr::hasAny($this->getDirty(), array_keys($versionableAttributes));
    }

    /**
     * The versionable attributes of the row as stored: the changed columns for a `DIFF`, every column for a
     * `SNAPSHOT`, read back from the database so that casts and unsynced attributes do not leak into the image.
     * Derived from overtrue/laravel-versionable (MIT), see LICENSES/laravel-versionable.md; typed on Core's enum.
     *
     * @param  array<string, mixed>  $replacements
     * @return array<string, mixed>
     */
    public function getVersionableAttributes(VersionStrategy $strategy, array $replacements = []): array
    {
        $versionable = $this->getVersionable();
        $refreshed = $this->getRefreshedModel($this);

        $keys = match ($strategy) {
            VersionStrategy::DIFF => array_keys($this->getDirty()),
            VersionStrategy::SNAPSHOT => array_keys($refreshed->attributesToArray()),
        };

        $attributes = Arr::only($refreshed->getRawOriginal(), $keys);

        if ($versionable !== []) {
            $attributes = Arr::only($attributes, $versionable);
        }

        return Arr::except(array_merge($attributes, $replacements), $this->getDontVersionable());
    }

    /**
     * @param  array<string, mixed>  $replacements
     * @return array<string, mixed>
     */
    public function getOriginalVersionableAttributes(VersionStrategy $strategy, array $replacements = []): array
    {
        $versionable = $this->getVersionable();
        $dontVersionable = $this->getDontVersionable();

        $refreshed = $this->getRefreshedModel($this);
        $originalRaw = $refreshed->getRawOriginal();

        $keys = match ($strategy) {
            VersionStrategy::DIFF => array_keys($this->getDirty()),
            VersionStrategy::SNAPSHOT => array_keys($refreshed->attributesToArray()),
        };

        $attributes = Arr::only($originalRaw, $keys);

        if (count($versionable) > 0) {
            $attributes = Arr::only($attributes, $versionable);
        }

        return Arr::except(array_merge($attributes, $replacements), $dontVersionable);
    }

    /**
     * @param  array<string, mixed>  $replacements
     * @param  string|DateTimeInterface|null  $time
     * @param  bool  $purgeOldVersionsAfterCreate  When true, removes all other version rows for this record after the new version is stored (used with snapshot checkpoints).
     *
     * @throws \Carbon\Exceptions\InvalidFormatException
     */
    public function createVersion(array $replacements = [], $time = null, bool $force = false, ?VersionStrategy $strategyForThisVersion = null, bool $purgeOldVersionsAfterCreate = false): ?Version
    {
        if ($this->getVersionStrategy() === false) {
            return null;
        }

        $effective_strategy = $strategyForThisVersion ?? $this->getVersionStrategy();

        if (! $force && ! $this->shouldBeVersioning() && $replacements === []) {
            return null;
        }

        return resolve(VersionWriterInterface::class)->write(VersionChange::forModel(
            model: $this,
            replacements: $replacements,
            time: $time,
            strategy: $effective_strategy,
            userId: $this->getVersionUserId(),
            encryptedAttributes: $this->encryptedVersionable,
        ));
    }

    /**
     * Persist a full SNAPSHOT of the current database row as a new version row (even when there are no dirty attributes).
     * Use for checkpoints when the model normally uses DIFF, or on a schedule.
     *
     * @param  array<string, mixed>  $replacements
     * @param  string|DateTimeInterface|null  $time
     * @param  bool  $purgeOldVersions  When true, deletes every other version row for this record after the snapshot is stored (full history compaction).
     *
     * @throws \Carbon\Exceptions\InvalidFormatException
     */
    public function createSnapshotVersion(array $replacements = [], mixed $time = null, bool $purgeOldVersions = false): ?Version
    {
        if ($this->getVersionStrategy() === false) {
            return null;
        }

        $this->refresh();

        return $this->createVersion($replacements, $time, true, VersionStrategy::SNAPSHOT, $purgeOldVersions);
    }

    /**
     * @param  self  $model
     */
    public function createInitialVersion(Model $model): ?Version
    {
        $version_strategy = $this->getVersionStrategy();

        if ($version_strategy === false) {
            return null;
        }

        // if ($model->has('versions')) {
        //     return $model->firstVersion()->first();
        // }

        // Read back after the insert: the attributes assigned in PHP miss every column that took a database
        // default, and the creation row is the anchor a revert rebuilds from (C09).
        /** @var self $model */
        $contents = $model->filterVersionableImage($model->getRefreshedModel($model)->getRawOriginal());

        return resolve(VersionWriterInterface::class)->write(new VersionChange(
            model: $model,
            type: VersionChangeType::Created,
            originalContents: [],
            contents: VersionChange::encryptSelected($contents, $model->encryptedVersionable),
            strategy: VersionStrategy::SNAPSHOT,
            time: $model->getAttribute('updated_at') instanceof DateTimeInterface ? $model->getAttribute('updated_at') : null,
            userId: $model->getVersionUserId(),
            encryptedAttributes: $model->encryptedVersionable,
        ));
    }

    /**
     * The user a version is attributed to, or null when the record carries no such
     * column. Deliberately not falling back to auth()->id() the way the package
     * does: a version written by a queue worker or a seeder has no user behind it,
     * and naming whoever happens to be logged in would be a lie.
     *
     * @return int|null
     */
    public function getVersionUserId()
    {
        $user_key = $this->getUserForeignKeyName();

        // Was isset($this['attributes'][$user_key]), which reads an attribute
        // literally named "attributes" through ArrayAccess: it does not exist, so
        // the branch never ran and every version was stored with no author.
        if (! array_key_exists($user_key, $this->getAttributes())) {
            return null;
        }

        // VersionChange stores it as ?int, which is what every user key in this
        // application is; anything else means the column holds something a version
        // row cannot reference.
        $user_id = $this->getAttribute($user_key);

        return is_numeric($user_id) ? (int) $user_id : null;
    }

    /**
     * Groups several writes of this root in one revision: the importers, a loop over locales (D28). The options name
     * the revision's reason and actor; by default the actor is the record's own author.
     */
    public function withinRevision(Closure $operation, ?VersionSetOptions $options = null): mixed
    {
        return app(VersionSetManagerInterface::class)->run(
            VersionSetRoot::forModel($this),
            $operation,
            $options ?? new VersionSetOptions(actor: $this->getVersionUserId()),
        );
    }

    public function getVersionStrategy(): VersionStrategy|false
    {
        if (NeverVersionedModels::includes(static::class)) {
            return false;
        }

        if (property_exists($this, 'versionStrategy')) {
            $configured = $this->versionStrategy;

            if ($configured !== null && $configured !== '') {
                return $configured instanceof VersionStrategy ? $configured : VersionStrategy::from((string) $configured);
            }
        }

        $model_class = static::class;

        // L1: static in-memory map — zero-cost after first resolution per request
        if (array_key_exists($model_class, self::$version_strategy_cache)) {
            return self::$version_strategy_cache[$model_class];
        }

        $settings_name = PerModelSettingResolver::nameFor(CoreDatabaseSeeder::VERSIONING_NAME_PREFIX, $this->getTable());

        $raw = app(PerModelSettingResolver::class)->value($settings_name, false);

        $strategy = $raw === false
            ? false
            : ($raw instanceof VersionStrategy ? $raw : VersionStrategy::from(is_scalar($raw) ? (string) $raw : ''));

        // Store in L1 for subsequent calls within the same request
        self::$version_strategy_cache[$model_class] = $strategy;

        return $strategy;
    }

    /**
     * History rows that represent a soft delete: the `trashed` rows. The restore that later clears the
     * deleted-at column is a `restored` row and is not one of them.
     *
     * @return Builder<Version>
     */
    public function trashingVersions(): Builder
    {
        return $this->versions()
            ->getQuery()
            ->where('change_type', VersionChangeType::Trashed);
    }

    /**
     * Reverting is an append-only, first-class operation: it opens a `Revert` version set
     * that points at the restored set, so a rollback is never mistaken for an ordinary edit.
     * The reverting save still writes a normal `Updated` record inside that set.
     */
    public function revertToVersion(int|string $id): bool
    {
        $version = $this->versions()->whereKey($id)->firstOrFail();
        // The record reverted is this one: the relation would not find a record in the bin, and the set locks
        // this instance.
        $version->setRelation('versionable', $this);

        return (bool) app(VersionSetManagerInterface::class)->run(
            VersionSetRoot::forModel($this),
            function () use ($version): bool {
                // Checked here, after the set has reread the row under lock, so a lock taken since this model
                // was loaded counts too. The write guard alone is not enough: it sees the record's own row,
                // and a revert also writes its translations and relations, which carry no lock of their own.
                throw_if(
                    method_exists($this, 'blocksWritesByCurrentUser') && $this->blocksWritesByCurrentUser(),
                    LockedModelException::class,
                    'This model is locked',
                );

                return (bool) $version->revert();
            },
            new VersionSetOptions(
                kind: VersionSetKind::Revert,
                actor: $this->getVersionUserId(),
                // The column is nullable, and VersionSetOptions itself refuses a null
                // on a revert; passing it through keeps that check where it belongs.
                revertedFrom: is_numeric($version_set_id = $version->getAttribute('version_set_id'))
                    ? (int) $version_set_id
                    : null,
            ),
        );
    }

    protected static function bootHasVersions(): void
    {
        static::created(function (Model $model): void {
            /** @var self $model */
            if (static::$versioning) {
                $model->createInitialVersion($model);
            }
        });

        static::updating(function (Model $model): void {
            /** @var self $model */
            $model->capturePendingVersionUpdate();
        });

        static::updated(function (Model $model): void {
            /** @var self $model */
            $model->writePendingVersionUpdate();
        });

        static::deleting(function (Model $model): void {
            /** @var self $model */
            $model->capturePendingVersionDelete();
        });

        static::deleted(function (Model $model): void {
            /** @var self $model */
            $model->writePendingVersionDelete();
        });
    }

    // public function getCreatorAttribute(): ?User
    // {
    //     if (! isset($this->creator)) {
    //         $this->creator = $this->created_by;
    //     }

    //     return $this->creator;
    // }

    /**
     * alias for created_by.
     *
     * @return Attribute<Model|null, never>
     */
    protected function creator(): Attribute
    {
        return $this->createdBy();
    }

    /**
     * @return Attribute<Model|null, never>
     */
    protected function createdBy(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! isset($this->_creator)) {
                    $first_version = $this->firstVersion?->{$this->getUserForeignKeyName()};
                    $this->_creator = $first_version ? $this->getUser($first_version) : null;
                }

                return $this->_creator;
            },
        );
    }

    /**
     * @return Attribute<Model|null, never>
     */
    protected function modifiedBy(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! isset($this->_modifier)) {
                    $last_version = $this->lastVersion?->{$this->getUserForeignKeyName()};
                    $this->_modifier = $last_version ? $this->getUser($last_version) : null;
                }

                return $this->_modifier;
            },
        );
    }

    private function capturePendingVersionUpdate(): void
    {
        $this->pending_version_update = null;
        $strategy = $this->getVersionStrategy();

        if (! static::$versioning || ! $strategy instanceof VersionStrategy) {
            return;
        }

        if (method_exists($this, 'shouldVersioning') && ! $this->shouldVersioning()) {
            return;
        }

        $keys = $this->filterVersionableKeys(array_keys($this->getDirty()), includeDeletedAt: true);

        if ($keys === []) {
            return;
        }

        $original = $strategy === VersionStrategy::SNAPSHOT
            ? $this->filterVersionableImage($this->getRawOriginal())
            : Arr::only($this->getRawOriginal(), $keys);
        $type = $this->isLeavingTheBin($keys) ? VersionChangeType::Restored : VersionChangeType::Updated;

        $this->pending_version_update = compact('strategy', 'keys', 'original', 'type');
    }

    private function writePendingVersionUpdate(): void
    {
        $pending = $this->pending_version_update;
        $this->pending_version_update = null;

        if ($pending === null) {
            return;
        }

        $contents = $pending['strategy'] === VersionStrategy::SNAPSHOT
            ? $this->filterVersionableImage($this->getAttributes())
            : Arr::only($this->getAttributes(), $pending['keys']);

        resolve(VersionWriterInterface::class)->write(new VersionChange(
            model: $this,
            type: $pending['type'],
            originalContents: VersionChange::encryptSelected($pending['original'], $this->encryptedVersionable),
            contents: VersionChange::encryptSelected($contents, $this->encryptedVersionable),
            strategy: $pending['strategy'],
            userId: $this->getVersionUserId(),
            encryptedAttributes: $this->encryptedVersionable,
        ));
    }

    private function capturePendingVersionDelete(): void
    {
        $this->pending_version_delete = null;
        $strategy = $this->getVersionStrategy();

        if (! static::$versioning || ! $strategy instanceof VersionStrategy) {
            return;
        }

        if ($this->isHardDelete()) {
            $this->pending_version_delete = [
                'mode' => 'hard',
                'original' => $this->filterVersionableImage($this->getRawOriginal()),
            ];

            return;
        }

        $deleted_at_column = $this->getVersionDeletedAtColumn();

        if ($deleted_at_column === null) {
            return;
        }

        $this->pending_version_delete = [
            'mode' => 'soft',
            'deleted_at_column' => $deleted_at_column,
            'original' => [$deleted_at_column => $this->getRawOriginal($deleted_at_column)],
        ];
    }

    private function writePendingVersionDelete(): void
    {
        $pending = $this->pending_version_delete;
        $this->pending_version_delete = null;

        if ($pending === null) {
            return;
        }

        if ($pending['mode'] === 'hard') {
            resolve(VersionWriterInterface::class)->write(new VersionChange(
                model: $this,
                type: VersionChangeType::Deleted,
                originalContents: VersionChange::encryptSelected($pending['original'], $this->encryptedVersionable),
                contents: [],
                strategy: VersionStrategy::SNAPSHOT,
                userId: $this->getVersionUserId(),
                encryptedAttributes: $this->encryptedVersionable,
            ));

            return;
        }

        $deleted_at_column = $pending['deleted_at_column'];

        resolve(VersionWriterInterface::class)->write(new VersionChange(
            model: $this,
            type: VersionChangeType::Trashed,
            originalContents: VersionChange::encryptSelected($pending['original'], $this->encryptedVersionable),
            contents: VersionChange::encryptSelected(
                [$deleted_at_column => $this->getRawOriginal($deleted_at_column)],
                $this->encryptedVersionable,
            ),
            strategy: VersionStrategy::DIFF,
            userId: $this->getVersionUserId(),
            encryptedAttributes: $this->encryptedVersionable,
        ));
    }

    /**
     * A hard delete removes the live row, so its tombstone must be a self-contained
     * SNAPSHOT: it cannot rely on a diff chain that the deletion itself breaks.
     * A soft delete is only an update of the deleted-at column and the row survives.
     * With soft deletes switched off for the model, a delete removes the row too, though
     * it is not flagged as forced while `deleting` runs.
     */
    private function isHardDelete(): bool
    {
        if (! method_exists($this, 'isForceDeleting')) {
            return true;
        }

        if ($this->isForceDeleting()) {
            return true;
        }

        return method_exists($this, 'softDeletesEnabledBySettings') && ! $this->softDeletesEnabledBySettings();
    }

    /**
     * An update that clears the deleted-at column takes the record out of the bin.
     *
     * @param  list<string>  $keys
     */
    private function isLeavingTheBin(array $keys): bool
    {
        $deleted_at_column = $this->getVersionDeletedAtColumn();

        return $deleted_at_column !== null
            && in_array($deleted_at_column, $keys, true)
            && $this->getRawOriginal($deleted_at_column) !== null
            && $this->getAttributes()[$deleted_at_column] === null;
    }

    /**
     * Whether a save of this model opens its revision scope: a root whose history is on, that owns translations or
     * registers relations, with something to write (D34). The others keep Eloquent's save and no extra transaction.
     */
    private function opensRevisionScopeOnSave(): bool
    {
        if (! static::$versioning || $this->getVersionStrategy() === false) {
            return false;
        }

        $owns_parts = method_exists($this, 'translations')
            || (method_exists($this, 'hasRegisteredRelations') && $this->hasRegisteredRelations());

        if (! $owns_parts) {
            return false;
        }

        return ! $this->exists
            || $this->isDirty()
            || (property_exists($this, 'pending_translations') && $this->pending_translations !== []);
    }

    private function getVersionDeletedAtColumn(): ?string
    {
        return method_exists($this, 'getDeletedAtColumn')
            ? $this->getDeletedAtColumn()
            : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function filterVersionableImage(array $attributes): array
    {
        return Arr::only($attributes, $this->filterVersionableKeys(array_keys($attributes)));
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function filterVersionableKeys(array $keys, bool $includeDeletedAt = false): array
    {
        $versionable = $this->getVersionable();

        if ($versionable !== []) {
            $allowed = $includeDeletedAt ? [...$versionable, 'deleted_at'] : $versionable;
            $keys = array_values(array_intersect($keys, $allowed));
        }

        return array_values(array_diff($keys, $this->getDontVersionable()));
    }

    private function getUser(int $userId): ?User
    {
        $user_class = user_class();

        /** @var User $user */
        $user = new $user_class;

        return $user->newQueryWithoutScopes()->find($userId);
    }

    // // TODO: sarà sicuramente da overridare per via delle primary key multiple
    // public function versions(): MorphMany
    // {
    // 	$version_model = $this->getVersionModel();
    // 	if (class_uses_trait($version_model, HasCompositeKey::class) || is_a($version_model, Pivot::class)) {
    // 		//TODO: da finire di scrivere, devo in qualche modo
    // 		return $this->morphMany($version_model, 'versionable', 'versionable_type', 'versionable_id');
    // 	}
    // 	return $this->morphMany($version_model, 'versionable');
    // }
}
