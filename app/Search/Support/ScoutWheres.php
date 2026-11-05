<?php

declare(strict_types=1);

namespace Modules\Core\Search\Support;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;

/**
 * Reads a Scout builder's `wheres` as the `field => value` map this application's engines expect.
 *
 * Scout 10 stored equality constraints that way: `$builder->wheres[$field] = $value`. Scout 11 changed
 * `Builder::where()` to append `['field' => …, 'operator' => …, 'value' => …]` to a list instead, so every
 * `$builder->wheres['locale']` and `array_diff_key($builder->wheres, …)` in this application silently stopped
 * matching anything.
 *
 * Only `=` constraints are collected, which is exactly what the old shape could express: these engines have
 * always treated `wheres` as equality filters, and an engine that cannot compile `>` should not be handed one
 * disguised as an equality. Ranges belong in the engine's own query building, not here.
 *
 * `whereIns` is untouched by that change and stays a `field => values` map, so it needs no normalization.
 */
final class ScoutWheres
{
    /**
     * @param  Builder<covariant Model>  $builder
     * @return array<string, mixed>
     */
    public static function map(Builder $builder): array
    {
        $map = [];

        foreach ($builder->wheres as $key => $where) {
            // A Scout 10 shaped entry, or one written directly into the array: keep it as it is.
            if (! is_array($where) || ! array_key_exists('field', $where)) {
                $map[$key] = $where;

                continue;
            }

            if (($where['operator'] ?? '=') !== '=') {
                continue;
            }

            $field = $where['field'];

            if (is_string($field) || is_int($field)) {
                $map[$field] = $where['value'] ?? null;
            }
        }

        return $map;
    }
}
