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
        $keys = $this->touchedKeys($ids);
        $this->attachWithinRevision($ids, $attributes, $touch);
        $this->flushPivotLabels($keys);
    }

    public function detach($ids = null, $touch = true)
    {
        $keys = $ids === null ? null : $this->touchedKeys($ids);
        $result = $this->detachWithinRevision($ids, $touch);
        $this->flushPivotLabels($keys);

        return $result;
    }

    public function sync($ids, $detaching = true)
    {
        $result = $this->syncWithinRevision($ids, $detaching);
        $this->flushPivotLabels(self::changedKeys($result, ['attached', 'detached', 'updated']));

        return $result;
    }

    public function toggle($ids, $touch = true)
    {
        $result = $this->toggleWithinRevision($ids, $touch);
        $this->flushPivotLabels(self::changedKeys($result, ['attached', 'detached']));

        return $result;
    }

    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        $keys = $this->touchedKeys($id);
        $result = $this->updateExistingPivotWithinRevision($id, $attributes, $touch);
        $this->flushPivotLabels($keys);

        return $result;
    }

    /**
     * The related keys of the changes a sync or toggle reports.
     *
     * @param  list<string>  $kinds
     * @return list<int|string>
     */
    private static function changedKeys(mixed $result, array $kinds): array
    {
        $keys = [];

        foreach ($kinds as $kind) {
            foreach (is_array($result) && is_array($result[$kind] ?? null) ? $result[$kind] : [] as $key) {
                if (is_int($key) || (is_string($key) && $key !== '')) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * The related keys named by the ids of attach, detach or updateExistingPivot: a model, a collection, a key, a
     * list of keys, or keys mapped to pivot attributes.
     *
     * @return list<int|string>
     */
    private function touchedKeys(mixed $ids): array
    {
        $keys = [];

        foreach ($this->parseIds($ids) as $index => $value) {
            $key = is_array($value) ? $index : $value;

            if (is_int($key) || (is_string($key) && $key !== '')) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param  list<int|string>|null  $related_keys
     */
    private function flushPivotLabels(?array $related_keys): void
    {
        app(CacheLabelInvalidator::class)->flushPivot($this, $related_keys);
    }
}
