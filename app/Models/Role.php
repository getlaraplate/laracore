<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use BackedEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Modules\Core\Cache\HasCache;
use Modules\Core\Contracts\ILockableModel;
use Modules\Core\Contracts\ISoftDeletableModel;
use Modules\Core\Database\Factories\RoleFactory;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Locking\Traits\HasLocks;
use Modules\Core\Models\Concerns\HasTypedRecursiveRelationships;
use Modules\Core\Models\Concerns\HasValidations;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\Core\Models\Pivot\ModelHasRole;
use Modules\Core\SoftDeletes\SoftDeletes;
use Override;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Models\Role as BaseRole;

final class Role extends BaseRole implements ILockableModel, ISoftDeletableModel
{
    use HasCache;
    use HasFactory;
    use HasLocks;
    use HasTypedRecursiveRelationships;
    use HasValidations {
        getRules as private getRulesTrait;
    }
    use HasVersions;
    use SoftDeletes {
        HasVersions::delete insteadof SoftDeletes;
        HasVersions::forceDelete insteadof SoftDeletes;
        SoftDeletes::delete as protected deleteWithTheBin;
        SoftDeletes::forceDelete as protected forceDeleteWithTheBin;
    }

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::Roles->value;

    /**
     * @var array<int,string>
     *
     * @psalm-suppress NonInvariantPropertyType
     * @psalm-suppress NonInvariantDocblockPropertyType
     */
    #[Override]
    protected $fillable = [
        'name',
        'guard_name',
        'description',
    ];

    /**
     * @var array<int,string>
     *
     * @psalm-suppress NonInvariantPropertyType
     * @psalm-suppress NonInvariantDocblockPropertyType
     */
    #[Override]
    protected $hidden = [
        'parent_id',
        'pivot',
    ];

    /**
     * @return BelongsToMany<User>
     */
    #[Override]
    public function users(): BelongsToMany
    {
        return parent::users()->using(ModelHasRole::class);
    }

    #[Override]
    public function getAllPermissions(): Collection
    {
        /** @psalm-suppress UndefinedThisPropertyFetch */
        $permissions = $this->permissions;

        /**
         * @psalm-suppress UndefinedThisPropertyFetch
         *
         * @var Role $parent
         */
        foreach ($this->ancestors as $parent) {
            $permissions = $permissions->merge($parent->permissions);
        }

        return $permissions->sort()->values();
    }

    /**
     * Whether the role, or one of its ancestors, holds a permission on a guard.
     *
     * A role belongs to one guard and only speaks for it: asked about another guard it holds
     * nothing, and so does an ancestor of another guard. A permission missing for the guard
     * is not held, it is not an error.
     *
     * @param  string|null  $guard_name  `null` is the guard of the current request, {@see Auth::getDefaultDriver()}.
     */
    public function hasPermission(string $permission, ?string $guard_name = null): bool
    {
        $guard_name ??= Auth::getDefaultDriver();

        if ($this->guard_name !== $guard_name) {
            return false;
        }

        try {
            if (parent::hasPermissionTo($permission, $guard_name)) {
                return true;
            }
        } catch (PermissionDoesNotExist) {
            return false;
        }

        /**
         * @psalm-suppress UndefinedThisPropertyFetch
         *
         * @var Role $parent
         */
        foreach ($this->ancestors as $parent) {
            if ($parent->hasPermission($permission, $guard_name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this role is the superadmin role. Superadmin has a priori full access
     * and must not have any permissions assigned.
     */
    public function isSuperAdminRole(): bool
    {
        return $this->name === config('permission.roles.superadmin');
    }

    /**
     * @param  string|int|array|Permission|Collection|BackedEnum  $permissions
     * @return $this
     */
    public function givePermissionTo(...$permissions): static
    {
        if ($this->isSuperAdminRole()) {
            $this->rejectAnyPermissionForSuperAdmin($permissions);
        }

        return parent::givePermissionTo(...$permissions);
    }

    /**
     * @param  string|int|array|Permission|Collection|BackedEnum  $permissions
     * @return $this
     */
    public function syncPermissions(...$permissions): static
    {
        if ($this->isSuperAdminRole()) {
            $this->rejectAnyPermissionForSuperAdmin($permissions);
        }

        return parent::syncPermissions(...$permissions);
    }

    public function getRules(): array
    {
        $rules = $this->getRulesTrait();
        $rules[self::DEFAULT_RULE] = array_merge($rules[self::DEFAULT_RULE], [
            'guard_name' => ['string', 'max:255'],
            'description' => ['string', 'max:255', 'nullable'],
            // 'locked_at' => ['date', 'nullable'],
        ]);
        $rules['create'] = array_merge($rules['create'], [
            'name' => [
                'required',
                'string',
                'max:255',
                $this->uniqueNameOnGuard(),
            ],
        ]);
        $rules['update'] = array_merge($rules['update'], [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                $this->uniqueNameOnGuard()->ignore($this->id, 'id'),
            ],
        ]);

        return $rules;
    }

    protected static function newFactory(): RoleFactory
    {
        return RoleFactory::new();
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Deletes through the bin inside the revision scope opened by {@see HasVersions::delete()}.
     */
    protected function deleteRecord(): ?bool
    {
        return $this->deleteWithTheBin();
    }

    /**
     * Force-deletes through the bin inside the revision scope opened by {@see HasVersions::forceDelete()}.
     */
    protected function forceDeleteRecord(): ?bool
    {
        return $this->forceDeleteWithTheBin();
    }

    /**
     * A role name is unique per guard: the `guest` of `/app` and the `guest` of `/api` are two roles.
     */
    private function uniqueNameOnGuard(): Unique
    {
        $default_guard = config('auth.defaults.guard', 'web');
        $guard_name = $this->guard_name ?? (is_string($default_guard) ? $default_guard : 'web');

        return Rule::unique(CoreTables::Roles->value, 'name')
            ->where('guard_name', $guard_name)
            ->whereNull('deleted_at');
    }

    /**
     * Superadmin role must not have any permission assigned (full access is a priori).
     *
     * @param  array<int, string|int|Permission|Collection|BackedEnum>  $permissions
     *
     * @throws ValidationException
     */
    private function rejectAnyPermissionForSuperAdmin(array $permissions): void
    {
        $to_check = collect($permissions)->flatten()->reject(fn (mixed $p): bool => empty($p));

        if ($to_check->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'permissions' => [
                __('The superadmin role cannot be assigned any permissions; it has full access by design.'),
            ],
        ]);
    }
}
