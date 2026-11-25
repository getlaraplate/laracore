<?php

declare(strict_types=1);

namespace Modules\Core\Helpers;

use Illuminate\Database\Eloquent\Model;

/**
 * The key of a model as code can use it. Eloquent declares getKey() mixed; a column holds an int or a string.
 */
final class ModelKey
{
    /**
     * The model's key, or null when it has none yet (or holds a value no key column stores).
     */
    public static function of(Model $model): int|string|null
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }
}
