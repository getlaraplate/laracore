<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Core\Contracts\IHidesAttributesFromAnonymous;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Concerns\HasDynamicContents;
use Modules\Core\Versioning\Contracts\VersionWriterInterface;
use Modules\Core\Versioning\Data\VersionChange;
use Modules\Core\Versioning\Exceptions\MissingVersionAnchorException;
use Override;

/**
 * One row of the history of a versioned record.
 *
 * @property int|string $id
 * @property int|null $version_set_id
 * @property int|null $sequence
 * @property VersionChangeType $change_type
 * @property string|null $relation_path
 * @property mixed $subject_key
 * @property string $versionable_type
 * @property int|string $versionable_id
 * @property int|null $user_id
 * @property array<string, mixed>|null $original_contents
 * @property array<string, mixed>|null $contents
 * @property VersionStrategy|null $version_strategy
 * @property string|null $connection_ref
 * @property string|null $table_ref
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $compacted_at
 *
 * Derived from overtrue/laravel-versionable (MIT), see LICENSES/laravel-versionable.md: the relation to the
 * versioned record, the ordering scopes, revert() and the uuid key were the package's.
 */
final class Version extends Model
{
    use HasFactory;

    /**
     * The history is append-only: only a compaction rewrites a row, and it marks `compacted_at` (D18, D19).
     */
    public const UPDATED_AT = null;

    /**
     * A password hash of the algorithms PHP's password_hash() produces (bcrypt, argon2i, argon2id).
     */
    private const string PASSWORD_HASH_PATTERN = '/^\$(2[aby]|argon2id?)\$/';

    /**
     * The columns in which {@see HasDynamicContents} stores the dynamic fields and their schema.
     *
     * @var list<string>
     */
    private const array DYNAMIC_CONTENTS_COLUMNS = ['components', 'shared_components', 'entity_id', 'presettable_id'];

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::Versions->value;

    /**
     * @var list<string>
     */
    #[Override]
    protected $guarded = [];

    /**
     * @var list<string>
     */
    #[Override]
    protected $with = ['versionable'];

    /**
     * @var array<int,string>
     *
     * @psalm-suppress NonInvariantPropertyType
     */
    #[Override]
    protected $hidden = [
        'user_id',
        'connection_ref',
        'table_ref',
        'versionable_type',
        'versionable_id',
    ];

    /**
     * @param  array<string, mixed>  $replacements
     *
     * @psalm-suppress MoreSpecificReturnType
     */
    public static function createForModel(Model $model, array $replacements = [], DateTimeInterface|string|null $time = null, ?VersionStrategy $strategyUsed = null): self
    {
        return resolve(VersionWriterInterface::class)->write(VersionChange::forModel(
            model: $model,
            replacements: $replacements,
            time: $time,
            strategy: $strategyUsed,
        ));
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function versionable(): MorphTo
    {
        return $this->morphTo('versionable');
    }

    #[Override]
    public function getIncrementing(): bool
    {
        return config('versionable.uuid') ? false : parent::getIncrementing();
    }

    #[Override]
    public function getKeyType(): string
    {
        return config('versionable.uuid') ? 'string' : parent::getKeyType();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrderOldestFirst(Builder $query): Builder
    {
        return $query->oldest()->oldest('id');
    }

    /**
     * Leaves out the rows of the aggregate image a baseline set appends after its own rows (D45).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithoutBaselineImage(Builder $query): Builder
    {
        $versions = $this->getTable();
        $sets = (new VersionSet)->getTable();

        return $query->whereNotExists(static function (QueryBuilder $image) use ($versions, $sets): void {
            $image->selectRaw('1')
                ->from($sets)
                ->whereColumn($sets . '.id', $versions . '.version_set_id')
                ->whereNotNull($sets . '.baseline_from_sequence')
                ->whereColumn($versions . '.sequence', '>=', $sets . '.baseline_from_sequence');
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrderLatestFirst(Builder $query): Builder
    {
        return $query->latest()->latest('id');
    }

    public function revert(): bool
    {
        return (bool) $this->revertWithoutSaving()?->save();
    }

    /**
     * Strategy used when this version row was written; used so revert can follow per-step semantics
     * even if the versionable model later changes global strategy.
     */
    public function getStoredVersionStrategy(): VersionStrategy
    {
        return $this->version_strategy instanceof VersionStrategy
            ? $this->version_strategy
            : VersionStrategy::DIFF;
    }

    /**
     * @return BelongsTo<VersionSet, Version>
     */
    public function versionSet(): BelongsTo
    {
        return $this->belongsTo(VersionSet::class);
    }

    /**
     * Strategy to apply for replay when reverting toward this version (falls back to the versionable's current strategy).
     */
    public function resolveReplayVersionStrategy(Model $versionable): VersionStrategy
    {
        if ($this->version_strategy instanceof VersionStrategy) {
            return $this->version_strategy;
        }

        if (method_exists($versionable, 'getVersionStrategy')) {
            /** @var VersionStrategy|false $current */
            $current = $versionable->getVersionStrategy();

            if ($current instanceof VersionStrategy) {
                return $current;
            }
        }

        return VersionStrategy::DIFF;
    }

    /**
     * The record with its versionable columns set to their state as of this row ({@see stateAsOf()}), not saved.
     * The deleted-at column keeps its live value: a revert never moves the record into or out of the bin, which
     * is the bin's own operation (D20, D61).
     *
     * @throws MissingVersionAnchorException
     */
    public function revertWithoutSaving(): ?Model
    {
        $versionable = $this->getCompleteVersionable();

        if (! $versionable instanceof Model) {
            return null;
        }

        $state = $this->stateAsOf();

        if (method_exists($versionable, 'getDeletedAtColumn')) {
            unset($state[$versionable->getDeletedAtColumn()]);
        }

        $versionable->setRawAttributes(array_merge($versionable->getRawOriginal(), $state));

        return $versionable;
    }

    /**
     * The versionable columns of the record as of this row: the nearest snapshot of the record at or before it,
     * then every later row up to this one applied forward in revision order, last write wins (C08). The strategy
     * stored on each row decides, never the model's current one. Only the record's own rows count, not those of
     * its translations or relations; a hard-delete tombstone is not a state of a live record.
     *
     *
     * @throws MissingVersionAnchorException
     *
     * @return array<string, mixed>
     */
    public function stateAsOf(): array
    {
        $anchor = $this->ownRowsUpToThis()
            ->where('version_strategy', VersionStrategy::SNAPSHOT)
            ->where('change_type', '!=', VersionChangeType::Deleted)
            ->orderByDesc('version_set_id')
            ->orderByDesc('sequence')
            ->first() ?? throw new MissingVersionAnchorException($this->id);

        $state = $anchor->contents ?? [];
        $later = $this->ownRowsUpToThis()
            ->where(static fn (Builder $query): Builder => $query
                ->where('version_set_id', '>', $anchor->version_set_id)
                ->orWhere(static fn (Builder $same_set): Builder => $same_set
                    ->where('version_set_id', $anchor->version_set_id)
                    ->where('sequence', '>', $anchor->sequence)))
            ->orderBy('version_set_id')
            ->orderBy('sequence')
            ->get();

        foreach ($later as $row) {
            $state = array_merge($state, $row->contents ?? []);
        }

        return $state;
    }

    /**
     * The previous versions that belong to the version.
     *
     * @return MorphMany<Version>
     */
    public function previousVersions(): MorphMany
    {
        $versionable = $this->getCompleteVersionable();

        return $versionable->latestVersions()
            ->where(function (Builder $query): void {
                $query->where('created_at', '<', $this->created_at)
                    ->orWhere(function (Builder $q): void {
                        $q->where('id', '<', $this->getKey())
                            ->where('created_at', '<=', $this->created_at);
                    });
            });
    }

    public function nextVersion(): ?static
    {
        $versionable = $this->getCompleteVersionable();

        if (! $versionable instanceof Model) {
            return null;
        }

        return $versionable->versions()
            ->where(function (Builder $query): void {
                $query->where('created_at', '>', $this->created_at)
                    ->orWhere(function (Builder $q): void {
                        $q->where('id', '>', $this->getKey())
                            ->where('created_at', '>=', $this->created_at);
                    });
            })
            ->orderOldestFirst()
            ->first();
    }

    /**
     * The version as a client receives it. Its contents are the raw attributes of the versioned record, so every
     * attribute the versioned model's own serialization withholds is masked in them: what the model hides from
     * everybody (passwords, remember tokens, two-factor secrets) and, for the anonymous user, what it hides from
     * the anonymous user. Any password hash left in a value is masked as well.
     */
    #[Override]
    public function toArray(): mixed
    {
        $serialized = parent::toArray();
        $masked = $this->maskedVersionableAttributes();

        foreach (['contents', 'original_contents', 'versionable_data'] as $key) {
            $data = $serialized[$key] ?? null;

            if (! is_array($data)) {
                continue;
            }

            foreach ($data as $attribute => &$value) {
                if (in_array($attribute, $masked, true) || (is_string($value) && preg_match(self::PASSWORD_HASH_PATTERN, $value) === 1)) {
                    $value = '[hidden]';
                }
            }

            unset($value);
            $serialized[$key] = $data;
        }

        return $serialized;
    }

    #[Override]
    protected static function booted(): void
    {
        self::creating(static function (self $version): void {
            if (config('versionable.uuid')) {
                $version->{$version->getKeyName()} = $version->{$version->getKeyName()} ?: (string) Str::orderedUuid();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'change_type' => VersionChangeType::class,
            'contents' => 'json',
            'original_contents' => 'json',
            'subject_key' => 'json',
            'version_strategy' => VersionStrategy::class,
            'created_at' => 'immutable_datetime',
            'compacted_at' => 'immutable_datetime',
        ];
    }

    /**
     * The attributes of the versioned model that its serialization withholds from the current caller.
     *
     * @return list<string>
     */
    private function maskedVersionableAttributes(): array
    {
        $type = $this->getAttributes()['versionable_type'] ?? null;

        if (! is_string($type) || $type === '') {
            return [];
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_subclass_of($class, Model::class)) {
            return [];
        }

        $model = new $class();
        $masked = $model->getHidden();

        // Dynamic contents hide their storage columns only because their own serialization flattens them into
        // fields: they hold the record's editorial values, which its history is for, not secrets.
        if (in_array(HasDynamicContents::class, class_uses_recursive($model), true)) {
            $masked = array_diff($masked, self::DYNAMIC_CONTENTS_COLUMNS);
        }

        if ($model instanceof IHidesAttributesFromAnonymous) {
            $user = Auth::user();

            if ($user instanceof User && $user->isGuest()) {
                array_push($masked, ...$model->anonymousHiddenAttributes());
            }
        }

        return array_values(array_filter($masked, is_string(...)));
    }

    /**
     * The rows of this record's own stream (no `relation_path`) up to this row, in revision order: earlier sets,
     * then the earlier or equal sequences of this set. Without the eager `versionable`.
     *
     * @return Builder<self>
     */
    private function ownRowsUpToThis(): Builder
    {
        return $this->newQueryWithoutRelationships()
            ->where('versionable_type', $this->getAttribute('versionable_type'))
            ->where('versionable_id', $this->getAttribute('versionable_id'))
            ->whereNull('relation_path')
            ->where(fn (Builder $query): Builder => $query
                ->where('version_set_id', '<', $this->getAttribute('version_set_id'))
                ->orWhere(fn (Builder $same_set): Builder => $same_set
                    ->where('version_set_id', $this->getAttribute('version_set_id'))
                    ->where('sequence', '<=', $this->getAttribute('sequence'))));
    }

    private function getCompleteVersionable(): ?Model
    {
        /** @var Model $versionable */
        $versionable = $this->versionable;

        if (! $versionable) {
            return null;
        }

        /** @phpstan-ignore property.notFound */
        if ($this->versionable_type) {
            /** @phpstan-ignore property.notFound */
            if ($this->connection_ref) {
                $versionable->setConnection($this->connection_ref);
            }

            /** @phpstan-ignore property.notFound */
            if ($this->table_ref) {
                $versionable->setTable($this->table_ref);
            }
        }

        return $versionable;
    }
}
