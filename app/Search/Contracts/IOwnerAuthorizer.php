<?php

declare(strict_types=1);

namespace Modules\Core\Search\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tells which owners of one model class the current user may see (M16). A module
 * registers one per owner type into {@see \Modules\Core\Search\OwnerAuthorizerRegistry}
 * so a media search hit is re-authorized through its owner, reusing the owner
 * module's own visibility rules; Core never knows those rules.
 */
interface IOwnerAuthorizer
{
    /**
     * @return class-string<Model>
     */
    public function ownerType(): string;

    /**
     * Owners of {@see ownerType()} the current user may see.
     *
     * @return Builder<covariant Model>
     */
    public function visibleOwners(): Builder;
}
