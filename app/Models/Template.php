<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Observers\HierarchyOwnerObserver;
use Modules\Core\Overrides\Model;
use Override;

/**
 * @property int $id
 * @property string $name The name of the template
 * @property string $content The blade template content
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
#[ObservedBy(HierarchyOwnerObserver::class)]
final class Template extends Model
{
    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::Templates->value;

    /**
     * The attributes that are mass assignable.
     */
    #[Override]
    protected $fillable = [
        'name',
        'content',
    ];

    public function getRules(): array
    {
        $rules = parent::getRules();
        $rules['create'] = array_merge($rules['create'], [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique(CoreTables::Templates->value)->where(function (QueryBuilder $query): void {
                    $query->whereNull('deleted_at');
                }),
            ],
        ]);
        $rules['update'] = array_merge($rules['update'], [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique(CoreTables::Templates->value)->where(function (QueryBuilder $query): void {
                    $query->whereNull('deleted_at');
                })->ignore($this->id, 'id'),
            ],
        ]);

        return $rules;
    }

    protected function casts(): array
    {
        return [
            // 'site_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'datetime',
        ];
    }
}
