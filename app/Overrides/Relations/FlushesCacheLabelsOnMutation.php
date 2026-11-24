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
    /**
     * How many mutators of this relation are running: sync and toggle call attach, detach and updateExistingPivot,
     * which must not flush on their own.
     */
    private int $cache_label_mutation_depth = 0;

    /**
     * The related keys touched by the running mutation and the mutators it called, null once one of them touched
     * keys it does not know (a detach of all).
     *
     * @var list<int|string>|null
     */
    private ?array $cache_label_touched_keys = [];

    public function attach($ids, array $attributes = [], $touch = true): void
    {
        $keys = $this->touchedKeys($ids);
        $this->flushingPivotLabelsOnce(fn (): null => $this->attachWithinRevision($ids, $attributes, $touch), static fn (): array => $keys);
    }

    public function detach($ids = null, $touch = true)
    {
        $keys = $ids === null ? null : $this->touchedKeys($ids);

        return $this->flushingPivotLabelsOnce(fn (): mixed => $this->detachWithinRevision($ids, $touch), static fn (): ?array => $keys);
    }

    public function sync($ids, $detaching = true)
    {
        return $this->flushingPivotLabelsOnce(
            fn (): mixed => $this->syncWithinRevision($ids, $detaching),
            static fn (mixed $result): array => self::changedKeys($result, ['attached', 'detached', 'updated']),
        );
    }

    public function toggle($ids, $touch = true)
    {
        return $this->flushingPivotLabelsOnce(
            fn (): mixed => $this->toggleWithinRevision($ids, $touch),
            static fn (mixed $result): array => self::changedKeys($result, ['attached', 'detached']),
        );
    }

    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        $keys = $this->touchedKeys($id);

        return $this->flushingPivotLabelsOnce(fn (): mixed => $this->updateExistingPivotWithinRevision($id, $attributes, $touch), static fn (): array => $keys);
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
     * Runs a mutator and flushes the pivot labels once, after the outermost mutator of this relation succeeded, with
     * every key it and the mutators it called touched: a sync of ten keys flushes once, not ten times.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $mutation
     * @param  callable(TResult): (list<int|string>|null)  $touched_keys
     * @return TResult
     */
    private function flushingPivotLabelsOnce(callable $mutation, callable $touched_keys): mixed
    {
        $outermost = $this->cache_label_mutation_depth === 0;

        if ($outermost) {
            $this->cache_label_touched_keys = [];
        }

        $this->cache_label_mutation_depth++;

        try {
            $result = $mutation();
            $keys = $touched_keys($result);
            $this->cache_label_touched_keys = $keys === null || $this->cache_label_touched_keys === null
                ? null
                : [...$this->cache_label_touched_keys, ...$keys];
        } finally {
            $this->cache_label_mutation_depth--;
        }

        if ($outermost) {
            $keys = $this->cache_label_touched_keys;
            $this->cache_label_touched_keys = [];
            app(CacheLabelInvalidator::class)->flushPivot($this, $keys === null ? null : array_values(array_unique($keys)));
        }

        return $result;
    }
}
