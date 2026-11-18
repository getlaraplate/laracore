<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;

/**
 * The roles a cached response is computed for: the user's roles and, when loaded, its groups.
 *
 * Shared by the response key ({@see \Modules\Core\Cache\Repository}) and the scope labels of the response, so the
 * two always name the same roles.
 */
final class CacheScope
{
    /**
     * The keys of the user's roles, then of the records of its loaded `groups`, `user_groups` and `user_roles`
     * relations, in that order and unsorted. A key that is not a scalar becomes `'0'`.
     *
     * @return list<string>
     */
    public static function roleKeys(User $user): array
    {
        $keys = $user->roles()->get()->map(static fn (Model $role): string => self::keyOf($role))->values()->all();

        foreach (['groups', 'user_groups', 'user_roles'] as $relation_name) {
            if (! method_exists($user, $relation_name) || ! $user->relationLoaded($relation_name)) {
                continue;
            }

            $related = $user->getRelation($relation_name);

            if (! $related instanceof Collection) {
                continue;
            }

            foreach ($related as $related_model) {
                if ($related_model instanceof Model) {
                    $keys[] = self::keyOf($related_model);
                }
            }
        }

        return $keys;
    }

    private static function keyOf(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '0';
    }
}
