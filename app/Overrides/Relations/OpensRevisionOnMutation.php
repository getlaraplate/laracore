<?php

declare(strict_types=1);

namespace Modules\Core\Overrides\Relations;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;
use Modules\Core\Versioning\Data\VersionSetOptions;
use Modules\Core\Versioning\Data\VersionSetRoot;

/**
 * The mutators of a many-to-many relation open a revision scope before their SQL when the relation is versioned on
 * either side (D42, D52): from the root the whole call is one set; from the other side the scope is opened on the
 * parent, whose own set stays empty and is dropped, and each link joins the set of its root (D43). A relation that
 * no root registers runs as Eloquent's, with no extra transaction; inside an open scope the call joins it.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Relations\BelongsToMany
 */
trait OpensRevisionOnMutation
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function attach($ids, array $attributes = [], $touch = true): void
    {
        $this->withinRevisionOfItsRoot(fn () => parent::attach($ids, $attributes, $touch));
    }

    /**
     * @return int
     */
    public function detach($ids = null, $touch = true)
    {
        return $this->withinRevisionOfItsRoot(fn () => parent::detach($ids, $touch));
    }

    /**
     * @param  \Illuminate\Support\Collection<array-key, mixed>|Model|array<array-key, mixed>|int|string  $ids
     * @return array{attached: array<mixed>, detached: array<mixed>, updated: array<mixed>}
     */
    public function sync($ids, $detaching = true)
    {
        return $this->withinRevisionOfItsRoot(fn () => parent::sync($ids, $detaching));
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toggle($ids, $touch = true)
    {
        return $this->withinRevisionOfItsRoot(fn () => parent::toggle($ids, $touch));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return int
     */
    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        return $this->withinRevisionOfItsRoot(fn () => parent::updateExistingPivot($id, $attributes, $touch));
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    private function withinRevisionOfItsRoot(Closure $operation): mixed
    {
        $manager = app(VersionSetManagerInterface::class);

        if ($manager->current() !== null || ! $this->isVersionedOnEitherSide()) {
            return $operation();
        }

        $parent = $this->getParent();

        return $manager->run(
            VersionSetRoot::forModel($parent),
            $operation,
            new VersionSetOptions(actor: method_exists($parent, 'getVersionUserId') ? $parent->getVersionUserId() : null),
        );
    }

    private function isVersionedOnEitherSide(): bool
    {
        return $this->registersThis($this->getParent(), $this->getForeignPivotKeyName())
            || $this->registersThis($this->getRelated(), $this->getRelatedPivotKeyName());
    }

    private function registersThis(Model $root, string $rootPivotKey): bool
    {
        if (! method_exists($root, 'versionedRelationThroughPivot') || ! method_exists($root, 'getVersionStrategy')) {
            return false;
        }

        if (! $root::getVersioning() || $root->getVersionStrategy() === false) {
            return false;
        }

        return $root->versionedRelationThroughPivot($this->getTable(), $rootPivotKey) !== null;
    }
}
