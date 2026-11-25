<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\Rule;
use Modules\Core\Contracts\IValidatableModel;
use Modules\Core\Contracts\IVersionedModel;
use Modules\Core\Database\Factories\LicenseFactory;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Concerns\HasValidations;
use Modules\Core\Models\Concerns\HasValidity;
use Modules\Core\Models\Concerns\HasVersions;
use Override;

/**
 * @property int $id
 * @property string $uuid Public license reference shown to customers
 * @property int $is_deleted Soft delete flag (see SoftDeletes trait scope)
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable $valid_from
 * @property \Carbon\CarbonImmutable|null $valid_to
 */
final class License extends Model implements IValidatableModel, IVersionedModel
{
    use HasFactory;
    use HasValidations {
        getRules as private getRulesFromTrait;
    }
    use HasValidity;
    use HasVersions;

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::Licenses->value;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'uuid',
    ];

    #[Override]
    protected $casts = [
        'is_active' => 'boolean',
    ];

    private bool $softDeletesEnabled = false;

    /**
     * The user that belongs to the license.
     *
     * @return HasOne<User>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function getRules(): array
    {
        $rules = $this->getRulesFromTrait();
        $rules[self::DEFAULT_RULE] = array_merge($rules[self::DEFAULT_RULE], [
            'uuid' => ['required', 'uuid', Rule::unique(CoreTables::Licenses->value, 'uuid')->ignore($this->getKey())],
            'valid_from' => ['date'],
            'valid_to' => ['nullable', 'date'],
        ]);

        return $rules;
    }

    protected static function newFactory(): LicenseFactory
    {
        return LicenseFactory::new();
    }

    #[Scope]
    /**
     * @param  Builder<License>  $query
     */
    protected function free(Builder $query): void
    {
        $query->doesntHave('user');
    }

    #[Scope]
    /**
     * @param  Builder<License>  $query
     */
    protected function occupied(Builder $query): void
    {
        $query->has('user');
    }

    #[Scope]
    /**
     * @param  Builder<License>  $query
     */
    protected function expired(Builder $query): void
    {
        $query->whereNotNull('valid_to')
            ->where('valid_to', '<', today());
    }
}
