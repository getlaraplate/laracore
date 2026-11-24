<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Cache\HasCache;
use Modules\Core\Casts\FiltersGroupCast;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Observers\AclObserver;
use Modules\Core\Overrides\Model;
use Modules\Core\Rules\AclRelationFilters;
use Modules\Core\Rules\AclScopeFilters;
use Modules\Core\Rules\QueryBuilder;
use Override;

/**
 * ACL (Access Control List) model for row-level security.
 *
 * ACLs define filters that restrict which records a user can access
 * when they have a specific permission. The system uses inheritance:
 *
 * - If a role has an ACL for a permission → use it (overrides parent)
 * - If a role has NO ACL → inherit from parent role
 * - If unrestricted=true → no filters applied (full access)
 * - Multiple non-hierarchical roles → combine with OR (union)
 *
 * @property int $id
 * @property int $permission_id
 * @property int|null $role_id
 * @property string|null $description The description of the acl
 * @property \Modules\Core\Casts\FiltersGroup|null $filters The filters of the acl
 * @property bool $unrestricted If true, this ACL is transparent and does not contribute filters to the query
 * @property int $priority Higher priority ACLs are evaluated first (for conflict resolution)
 * @property bool $is_active If false, this ACL is ignored (allows temporary disable)
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
#[ObservedBy([AclObserver::class])]
final class ACL extends Model
{
    use HasCache;

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::Acls->value;

    #[Override]
    protected $fillable = [
        'permission_id',
        'role_id',       // Optional: scopes the ACL to a single role; null = every role holding the permission
        'filters',       // Stored as JSON - query builder filters
        'sort',          // Optional: stored as JSON
        'description',   // Optional: human readable description
        'unrestricted',  // If true, no filters applied (full access)
        'priority',      // Higher priority ACLs evaluated first
        'is_active',     // If false, ACL is ignored (DB column name)
    ];

    /**
     * The permission that belongs to the ACL.
     *
     * @return BelongsTo<Permission>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    /**
     * The role this ACL is scoped to, when set. A null role means the ACL applies
     * to every role that holds its permission (the legacy, unscoped behavior).
     *
     * @return BelongsTo<Role>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function getRules(): array
    {
        $rules = parent::getRules();
        $rules[Model::DEFAULT_RULE] = array_merge($rules[Model::DEFAULT_RULE], [
            'permission_id' => ['required', 'exists:' . CoreTables::Permissions->value . ',id'],
            'role_id' => ['nullable', 'exists:' . CoreTables::Roles->value . ',id'],
            'filters' => [new QueryBuilder(), new AclRelationFilters(), new AclScopeFilters()],
            'sort.*.property' => ['string'],
            'sort.*.direction' => ['in:asc,desc,ASC,DESC'],
            'description' => ['string', 'max:255', 'nullable'],
            'unrestricted' => ['boolean'],
            'priority' => ['integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ]);

        return $rules;
    }

    /**
     * Check if this ACL grants unrestricted access.
     */
    public function isUnrestricted(): bool
    {
        return $this->unrestricted === true;
    }

    /**
     * Check if this ACL has any filters defined.
     */
    public function hasFilters(): bool
    {
        return $this->filters !== null && $this->filters->filters !== [];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function forPermission(Builder $query, int $permission_id): Builder
    {
        return $query->where('permission_id', $permission_id);
    }

    /**
     * Scope to get only enabled ACLs.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to order by priority (highest first).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function byPriority(Builder $query): Builder
    {
        return $query->orderByDesc('priority');
    }

    protected function casts(): array
    {
        return [
            'filters' => FiltersGroupCast::class,
            'sort' => 'array',
            'unrestricted' => 'boolean',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
