<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Modules\Core\Cache\HasCache;
use Modules\Core\Casts\ActionEnum;
use Modules\Core\Database\Factories\PermissionFactory;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Concerns\HasValidations;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\Core\Models\Concerns\ReturnsVersionedRelations;
use Override;
use Spatie\Permission\Models\Permission as ModelsPermission;

final class Permission extends ModelsPermission
{
    use HasCache;
    use HasFactory;
    use HasValidations {
        getRules as private getRulesTrait;
    }
    use HasVersions;
    use ReturnsVersionedRelations;

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::Permissions->value;

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
    ];

    #[Override]
    protected $guarded = [
        'id',
        'connection_name',
        'table_name',
    ];

    /**
     * @var array<int,string>
     *
     * @psalm-suppress NonInvariantPropertyType
     * @psalm-suppress NonInvariantDocblockPropertyType
     */
    #[Override]
    protected $hidden = [
        'pivot',
    ];

    #[Override]
    protected $attributes = [
        'guard_name' => 'web',
    ];

    private array $append = [
        'action',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->guarded = array_merge($this->guarded ?? [], [
            'connection_name',
            'table_name',
        ]);
    }

    public function getRules(): array
    {
        $rules = $this->getRulesTrait();
        $rules[self::DEFAULT_RULE] = array_merge($rules[self::DEFAULT_RULE], [
            'guard_name' => ['string', 'max:255'],
            'description' => ['string', 'max:255', 'nullable'],
        ]);
        $rules['create'] = array_merge($rules['create'], [
            'name' => ['required', 'string', 'max:255', 'regex:/^\\w+\\.\\w+\\.\\w+$/', $this->uniqueNameOnGuard()],
        ]);
        $rules['update'] = array_merge($rules['update'], [
            'name' => ['sometimes', 'string', 'max:255', 'regex:/^\\w+\\.\\w+\\.\\w+$/', $this->uniqueNameOnGuard()->ignore($this->id)],
        ]);

        return $rules;
    }

    /**
     * @return HasMany<ACL>
     */
    public function acls(): HasMany
    {
        return $this->hasMany(ACL::class);
    }

    protected static function newFactory(): PermissionFactory
    {
        return PermissionFactory::new();
    }

    protected function getActionAttribute(): ?ActionEnum
    {
        if ($this->name === null) {
            return null;
        }

        $splitted = explode('.', $this->name);

        return ActionEnum::tryFrom(array_pop($splitted));
    }

    /**
     * A permission name is unique per guard: every permission exists once for each guard it serves.
     */
    private function uniqueNameOnGuard(): Unique
    {
        $default_guard = config('auth.defaults.guard', 'web');
        $guard_name = $this->guard_name ?? (is_string($default_guard) ? $default_guard : 'web');

        return Rule::unique(CoreTables::Permissions->value, 'name')->where('guard_name', $guard_name);
    }
}
