<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Modules\Core\Casts\CronExpression as CronExpressionCast;
use Modules\Core\Contracts\IActivatableModel;
use Modules\Core\Contracts\ILockableModel;
use Modules\Core\Database\Factories\CronJobFactory;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Locking\Traits\HasLocks;
use Modules\Core\Models\Concerns\HasActivation;
use Modules\Core\Overrides\Model;
use Modules\Core\Rules\CronExpression as CronExpressionRule;
use Modules\Core\Rules\CronParametersList;
use Override;

/**
 * @property int $id
 * @property string $name The name of the cron job
 * @property string $command The command of the cron job
 * @property \Cron\CronExpression $schedule The schedule of the cron job
 * @property bool $is_active Is the cron job active
 * @property bool $on_one_server Run on a single server when several share the scheduler
 * @property bool $without_overlapping Skip a run while the previous one is still running
 * @property string|null $description The description of the cron job
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 * @property CarbonImmutable|null $locked_at The date and time when the entity was locked
 * @property int|null $locked_user_id The user who locked the entity
 * @property CarbonImmutable|null $locked_until The moment the lock expires; null means it never does
 * @property array<array-key, mixed> $parameters The parameters of the cron job
 */
final class CronJob extends Model implements IActivatableModel, ILockableModel
{
    // region Traits
    use HasActivation {
        HasActivation::casts as private activationCasts;
    }
    use HasLocks;
    // endregion

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::CronJobs->value;

    /**
     * @var array<int,string>
     *
     * @psalm-suppress NonInvariantPropertyType
     * @psalm-suppress NonInvariantDocblockPropertyType
     */
    #[Override]
    protected $fillable = [
        'name',
        'command',
        'parameters',
        'schedule',
        'description',
        'on_one_server',
        'without_overlapping',
    ];

    #[Override]
    protected $attributes = [
        'parameters' => '[]',
        'on_one_server' => true,
        'without_overlapping' => true,
    ];

    public function getRules(): array
    {
        $rules = parent::getRules();
        $rules[Model::DEFAULT_RULE] = array_merge($rules[Model::DEFAULT_RULE], [
            // The name of the command only: its arguments go in `parameters`, where Laravel escapes them.
            'command' => ['required', 'string', 'max:255', 'regex:/^\S+$/'],
            'parameters' => ['required', new CronParametersList()],
            'schedule' => ['required', new CronExpressionRule()],
            'description' => ['string', 'max:255', 'nullable'],
            'is_active' => ['boolean', 'required'],
            'on_one_server' => ['boolean'],
            'without_overlapping' => ['boolean'],
        ]);
        $rules['create'] = array_merge($rules['create'], [
            'name' => [
                'required',
                'string',
                'max:255',
                /** @var \Illuminate\Database\Query\Builder $query */
                Rule::unique(CoreTables::CronJobs->value)->where(static fn ($query) => $query->whereNull('deleted_at')), // @pest-ignore-type
            ],
        ]);
        $rules['update'] = array_merge($rules['update'], [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                /** @var \Illuminate\Database\Query\Builder $query */
                Rule::unique(CoreTables::CronJobs->value)->where(static fn ($query) => $query->whereNull('deleted_at'))->ignore($this->id, 'id'), // @pest-ignore-type
            ],
        ]);

        return $rules;
    }

    /**
     * When the schedule was last due, null while the job is inactive. Nothing records the runs themselves, so
     * this is the time the scheduler last had to start it.
     */
    public function previousRunAt(): ?CarbonImmutable
    {
        return $this->is_active ? CarbonImmutable::instance($this->schedule->getPreviousRunDate(now())) : null;
    }

    /**
     * When the schedule is next due, null while the job is inactive.
     */
    public function nextRunAt(): ?CarbonImmutable
    {
        return $this->is_active ? CarbonImmutable::instance($this->schedule->getNextRunDate(now())) : null;
    }

    protected static function newFactory(): CronJobFactory
    {
        return CronJobFactory::new();
    }

    protected function casts(): array
    {
        return array_merge($this->activationCasts(), [
            'name' => 'string',
            'command' => 'string',
            'parameters' => 'json',
            'on_one_server' => 'boolean',
            'without_overlapping' => 'boolean',
            'schedule' => CronExpressionCast::class,
            'description' => 'string',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'datetime',
        ]);
    }
}
