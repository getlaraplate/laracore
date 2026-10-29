<?php

declare(strict_types=1);

namespace Modules\Core\Models\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;
use Modules\Core\Versioning\Contracts\VersionWriterInterface;
use Modules\Core\Versioning\Data\VersionChange;
use Modules\Core\Versioning\Data\VersionSetOptions;
use Modules\Core\Versioning\Data\VersionSetRoot;

/**
 * A part of a versioned record that has no history of its own: its changes are rows of its owner's history (D10).
 * A translation writes one row per locale on the owner, `relation_path = translations` and `subject_key = {locale}`,
 * with the weight of the owner's strategy (D11, D28); the first row of a locale is always a complete image read
 * back from the database (D45 rule 3). The owner is the record that handed itself over while writing the part
 * ({@see self::recordOnHistoryOf()}), otherwise the one named by `IsPartOfParent::parentRelation()`; a part with
 * neither, written on its own, records nothing.
 *
 * @phpstan-require-extends Model
 */
trait RecordsChangesOnOwner
{
    /**
     * What `updating` or `deleting` captured for the row the matching `updated` or `deleted` writes.
     *
     * @var array{original: array<string, mixed>, keys: list<string>}|null
     */
    private ?array $pending_owner_change = null;

    /**
     * The owner that is writing this part, when it handed itself over.
     */
    private ?Model $history_owner = null;

    public static function bootRecordsChangesOnOwner(): void
    {
        static::created(static function (Model $part): void {
            $part->recordOnOwner(VersionChangeType::Created);
        });

        static::updating(static function (Model $part): void {
            $part->captureForOwner(array_keys($part->getDirty()));
        });

        static::updated(static function (Model $part): void {
            $part->recordOnOwner(VersionChangeType::Updated);
        });

        static::deleting(static function (Model $part): void {
            $part->captureForOwner([]);
        });

        static::deleted(static function (Model $part): void {
            $part->recordOnOwner(VersionChangeType::Deleted);
        });
    }

    /**
     * Saves the part inside its owner's revision scope when no scope is open, so the part and the row it records on
     * its owner commit together (D52); inside an open scope the row joins its owner's set as it is.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        return (bool) $this->withinOwnerScope(fn (): bool => parent::save($options));
    }

    /**
     * Deletes the part inside its owner's revision scope when no scope is open, as {@see self::save()}.
     */
    public function delete(): ?bool
    {
        return $this->withinOwnerScope(fn (): ?bool => parent::delete());
    }

    /**
     * Makes the owner writing this part the one whose history records it: the instance being written, not a copy
     * reloaded from the database, and a concrete class even when the part's own relation names an abstract one.
     */
    public function recordOnHistoryOf(Model $owner): void
    {
        $this->history_owner = $owner;
    }

    /**
     * The fields of this part that are its owner's state, as they are now: the locale's row of the owner's snapshot
     * set at a hard delete (D55).
     *
     * @return array<string, mixed>
     */
    public function imageOnOwner(): array
    {
        return $this->ownerImage($this->getAttributes());
    }

    /**
     * @param  list<string>  $dirtyKeys
     */
    private function captureForOwner(array $dirtyKeys): void
    {
        $this->pending_owner_change = [
            'original' => $this->ownerImage($this->getRawOriginal()),
            'keys' => array_values(array_intersect($dirtyKeys, $this->ownerImageKeys())),
        ];
    }

    private function recordOnOwner(VersionChangeType $type): void
    {
        $pending = $this->pending_owner_change;
        $this->pending_owner_change = null;
        $owner = $this->versionedOwner();

        // A part removed by its owner's hard delete is in the owner's snapshot set already (D54).
        if (! $owner instanceof Model || resolve(VersionSetManagerInterface::class)->isBeingHardDeleted($owner)) {
            return;
        }

        $strategy = $owner->getVersionStrategy();

        [$original, $contents, $strategy] = match ($type) {
            VersionChangeType::Created => [[], $this->ownerImage($this->readBack()), VersionStrategy::SNAPSHOT],
            VersionChangeType::Deleted => [$pending['original'] ?? [], [], VersionStrategy::SNAPSHOT],
            default => $strategy === VersionStrategy::SNAPSHOT
                ? [$pending['original'] ?? [], $this->ownerImage($this->readBack()), VersionStrategy::SNAPSHOT]
                : [Arr::only($pending['original'] ?? [], $pending['keys'] ?? []), Arr::only($this->ownerImage($this->getAttributes()), $pending['keys'] ?? []), VersionStrategy::DIFF],
        };

        if ($type === VersionChangeType::Updated && $contents === []) {
            return;
        }

        resolve(VersionWriterInterface::class)->write(new VersionChange(
            model: $owner,
            type: $type,
            originalContents: $original,
            contents: $contents,
            strategy: $strategy,
            userId: $owner->getVersionUserId(),
            relationPath: 'translations',
            subjectKey: ['locale' => $this->getAttribute('locale')],
        ));
    }

    /**
     * The owner whose history records this part, or null when it has none or its history is off.
     */
    private function versionedOwner(): ?Model
    {
        $owner = $this->history_owner ?? $this->parentOwner();

        if (! $owner instanceof Model || ! method_exists($owner, 'getVersionStrategy') || ! $owner::getVersioning()) {
            return null;
        }

        return $owner->getVersionStrategy() === false ? null : $owner;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    private function withinOwnerScope(Closure $operation): mixed
    {
        $manager = app(VersionSetManagerInterface::class);
        $owner = $manager->current() === null ? $this->versionedOwner() : null;

        if (! $owner instanceof Model || ! $owner->exists) {
            return $operation();
        }

        return $manager->run(
            VersionSetRoot::forModel($owner),
            $operation,
            new VersionSetOptions(actor: $owner->getVersionUserId()),
        );
    }

    private function parentOwner(): ?Model
    {
        if (! $this instanceof IsPartOfParent) {
            return null;
        }

        $relation = $this->parentRelation();

        if (! $this->relationLoaded($relation)) {
            $related = $this->{$relation}()->getRelated();

            // Decided on the class before any query: a part saved on its own must not read its owner when the
            // owner's history is off.
            if (! method_exists($related, 'getVersionStrategy') || ! $related::getVersioning() || $related->getVersionStrategy() === false) {
                return null;
            }

            // Without global scopes, since the owner of a translation is hidden by its locale scope until it has
            // one, and without its eager loads, which the history does not read.
            $this->setRelation($relation, $this->{$relation}()->withoutGlobalScopes()->setEagerLoads([])->first());
        }

        $owner = $this->getRelation($relation);

        return $owner instanceof Model ? $owner : null;
    }

    /**
     * The fields of the part that are the owner's state: its fillable fields but the locale and the keys.
     *
     * @return list<string>
     */
    private function ownerImageKeys(): array
    {
        return array_values(array_filter(
            $this->getFillable(),
            static fn (string $field): bool => $field !== 'locale' && ! str_ends_with($field, '_id'),
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function ownerImage(array $attributes): array
    {
        return Arr::only($attributes, $this->ownerImageKeys());
    }

    /**
     * @return array<string, mixed>
     */
    private function readBack(): array
    {
        $row = $this->newQueryWithoutScopes()->whereKey($this->getKey())->toBase()->first();

        return $row === null ? $this->getAttributes() : (array) $row;
    }
}
