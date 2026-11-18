<?php

declare(strict_types=1);

namespace Modules\Core\Overrides\Relations;

use Modules\Core\Cache\Labels\CacheLabelInvalidator;

/**
 * Flushes the labels of the pivot, parent and related tables after a successful pivot mutation. Wraps the
 * mutators of {@see OpensRevisionOnMutation}, which the using class must alias as `*WithinRevision`.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Relations\BelongsToMany
 */
trait FlushesCacheLabelsOnMutation
{
    public function attach($ids, array $attributes = [], $touch = true): void
    {
        $this->attachWithinRevision($ids, $attributes, $touch);
        $this->flushPivotLabels();
    }

    public function detach($ids = null, $touch = true)
    {
        $result = $this->detachWithinRevision($ids, $touch);
        $this->flushPivotLabels();

        return $result;
    }

    public function sync($ids, $detaching = true)
    {
        $result = $this->syncWithinRevision($ids, $detaching);
        $this->flushPivotLabels();

        return $result;
    }

    public function toggle($ids, $touch = true)
    {
        $result = $this->toggleWithinRevision($ids, $touch);
        $this->flushPivotLabels();

        return $result;
    }

    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        $result = $this->updateExistingPivotWithinRevision($id, $attributes, $touch);
        $this->flushPivotLabels();

        return $result;
    }

    private function flushPivotLabels(): void
    {
        app(CacheLabelInvalidator::class)->flushPivot($this);
    }
}
