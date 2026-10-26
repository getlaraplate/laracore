<?php

declare(strict_types=1);

namespace Modules\Core\Overrides\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as BaseBelongsToMany;

/**
 * Laravel's many-to-many relation whose mutators open the revision scope of a versioned relation (D42).
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 * @template TPivotModel of \Illuminate\Database\Eloquent\Relations\Pivot = \Illuminate\Database\Eloquent\Relations\Pivot
 * @template TAccessor of string = 'pivot'
 *
 * @extends BaseBelongsToMany<TRelatedModel, TDeclaringModel, TPivotModel, TAccessor>
 */
class BelongsToMany extends BaseBelongsToMany
{
    use OpensRevisionOnMutation;
}
