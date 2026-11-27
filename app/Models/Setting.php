<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use InvalidArgumentException;
use Modules\Core\Cache\HasCache;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Database\Factories\SettingFactory;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Concerns\HasApprovals;
use Modules\Core\Observers\SettingObserver;
use Modules\Core\Overrides\Model;
use Modules\Core\Rules\SettingValue;
use Override;

/**
 * @property int $id
 * @property string $name The name of the setting
 * @property bool $encrypted Is the value encrypted
 * @property SettingTypeEnum $type The type of the setting
 * @property string $group_name The group name of the setting
 * @property string $description The description of the setting
 * @property string|null $module Owning module, null when the setting was not written by a seeder
 * @property bool $is_internal Written by a first-party module seeder
 * @property bool $managed Value written by a command, read-only in the panel
 * @property bool $is_public Readable by guests through the settings ACL
 * @property string|null $action_command Artisan command line run from the settings grid, with {attribute} placeholders; seeded only
 * @property bool $action_queued Queue the action command instead of running it inside the request
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 * @property mixed $value The value of the setting
 * @property array<array-key, mixed>|null $choices Constrained available values
 * @property array<array-key, mixed>|null $seeded_value Last value written by the seeder; drift is value !== seeded_value
 */
#[ObservedBy(SettingObserver::class)]
final class Setting extends Model
{
    use HasApprovals {
        HasApprovals::requiresApprovalWhen as private requiresApprovalWhenTrait;
    }
    use HasCache;

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::Settings->value;

    /**
     * @var array<int,string>
     *
     * @psalm-suppress NonInvariantPropertyType
     * @psalm-suppress NonInvariantDocblockPropertyType
     */
    #[Override]
    protected $fillable = [
        'name',
        'value',
        'encrypted',
        'choices',
        'type',
        'group_name',
        'description',
        'module',
        'seeded_value',
        'is_internal',
        'is_public',
    ];

    /**
     * The command a setting runs is code-owned: it is neither mass assignable nor exposed in
     * serialized output, since public settings are readable by guests.
     *
     * @var list<string>
     */
    #[Override]
    protected $hidden = [
        'action_command',
        'action_queued',
    ];

    #[Override]
    protected $attributes = [
        'action_queued' => false,
        'encrypted' => false,
        'is_internal' => false,
        'managed' => false,
        'is_public' => false,
        'type' => 'string',
        'group_name' => 'base',
    ];

    /**
     * True while writeManaged() saves: the value is then command-owned and skips approval.
     */
    private bool $writingManaged = false;

    /**
     * Write the value of a setting a command owns (the panel shows it read-only). The write
     * never becomes a pending approval, and the observer refreshes the settings overlay.
     *
     * @throws InvalidArgumentException If the setting does not exist or is not managed.
     */
    public static function writeManaged(string $name, mixed $value): void
    {
        $setting = self::query()->where('name', $name)->first();

        if (! $setting instanceof self) {
            throw new InvalidArgumentException("Setting {$name} does not exist.");
        }

        if (! $setting->managed) {
            throw new InvalidArgumentException("Setting {$name} is not managed by a command.");
        }

        $setting->writingManaged = true;

        try {
            $setting->setSkipValidation(true);
            $setting->value = $value;
            $setting->save();
        } finally {
            $setting->writingManaged = false;
        }
    }

    public function getRules(): array
    {
        $rules = parent::getRules();
        $rules[Model::DEFAULT_RULE] = array_merge($rules[Model::DEFAULT_RULE], [
            'encrypted' => ['boolean', 'required'],
            'value' => [new SettingValue()],
            'is_internal' => ['boolean'],
            'is_public' => ['boolean'],
            'choices' => ['sometimes', 'nullable'],
            'choices.*' => ['filled'],
            'type' => ['required', new Enum(SettingTypeEnum::class)],
            'group_name' => ['string', 'required', 'max:50'],
            'description' => ['string', 'max:255', 'nullable'],
            'locked_at' => ['date', 'nullable'],
        ]);
        $rules['create'] = array_merge($rules['create'], [
            'name' => [
                'required',
                'string',
                'max:255',
                /** @var Builder $query */
                Rule::unique(CoreTables::Settings->value)->where(function ($query): void { // @pest-ignore-type
                    $query->where('deleted_at', null);
                }),
            ],
        ]);
        $rules['update'] = array_merge($rules['update'], [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                /** @var Builder $query */
                Rule::unique(CoreTables::Settings->value)->where(function ($query): void { // @pest-ignore-type
                    $query->where('deleted_at', null);
                })->ignore($this->id, 'id'),
            ],
        ]);

        return $rules;
    }

    /**
     * Whether the saved value is a scalar missing from a non-empty choice list, i.e. a choice a
     * refresh no longer offers. List values (checkbox settings) are never flagged.
     */
    public function isValueOutsideChoices(): bool
    {
        $choices = $this->choices;
        $value = $this->value;

        if (! is_array($choices) || $choices === [] || ! is_scalar($value)) {
            return false;
        }

        $offered = array_map(static fn (mixed $choice): ?string => is_scalar($choice) ? (string) $choice : null, $choices);

        return ! in_array((string) $value, $offered, true);
    }

    /**
     * The value is stored as JSON: validate what it decodes to, as the form and the API send it.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function getAttributesForValidation(): array
    {
        $attributes = parent::getAttributesForValidation();

        if (array_key_exists('value', $attributes)) {
            $attributes['value'] = $this->value;
        }

        return $attributes;
    }

    protected static function newFactory(): SettingFactory
    {
        return SettingFactory::new();
    }

    protected function setTypeAttribute(mixed $value): void
    {
        $this->attributes['type'] = ($value instanceof SettingTypeEnum ? $value : (is_string($value) ? SettingTypeEnum::tryFrom($value) : null)) ?? SettingTypeEnum::String;
    }

    protected function casts(): array
    {
        return [
            'value' => 'json',
            'encrypted' => 'boolean',
            'is_internal' => 'boolean',
            'managed' => 'boolean',
            'is_public' => 'boolean',
            'action_queued' => 'boolean',
            'choices' => 'array',
            'seeded_value' => 'json',
            'type' => SettingTypeEnum::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Presentation-only fields (description, group) and the choices a refresh command writes
     * are applied directly: users cannot edit choices from the panel. Any other change follows
     * the shared approval rule: a writer holding the approve permission, when one approval is
     * enough, saves directly; everybody else goes through approval.
     */
    protected function requiresApprovalWhen(array $modifications): bool
    {
        if ($this->writingManaged) {
            return false;
        }

        $guarded = array_intersect_key(
            $modifications,
            array_flip(array_diff($this->getFillable(), ['description', 'group_name', 'choices'])),
        );

        if ($guarded === []) {
            return false;
        }

        return $this->requiresApprovalWhenTrait($modifications);
    }
}
