<?php

declare(strict_types=1);

namespace Modules\Core\Overrides\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany as BaseMorphToMany;

/**
 * Laravel's polymorphic many-to-many relation whose mutators open the revision scope of a versioned relation (D42).
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 * @template TPivotModel of \Illuminate\Database\Eloquent\Relations\Pivot = \Illuminate\Database\Eloquent\Relations\MorphPivot
 * @template TAccessor of string = 'pivot'
 *
 * @extends BaseMorphToMany<TRelatedModel, TDeclaringModel, TPivotModel, TAccessor>
 */
class MorphToMany extends BaseMorphToMany
{
    use FlushesCacheLabelsOnMutation, OpensRevisionOnMutation {
        FlushesCacheLabelsOnMutation::attach insteadof OpensRevisionOnMutation;
        OpensRevisionOnMutation::attach as attachWithinRevision;
        FlushesCacheLabelsOnMutation::detach insteadof OpensRevisionOnMutation;
        OpensRevisionOnMutation::detach as detachWithinRevision;
        FlushesCacheLabelsOnMutation::sync insteadof OpensRevisionOnMutation;
        OpensRevisionOnMutation::sync as syncWithinRevision;
        FlushesCacheLabelsOnMutation::toggle insteadof OpensRevisionOnMutation;
        OpensRevisionOnMutation::toggle as toggleWithinRevision;
        FlushesCacheLabelsOnMutation::updateExistingPivot insteadof OpensRevisionOnMutation;
        OpensRevisionOnMutation::updateExistingPivot as updateExistingPivotWithinRevision;
    }
}
