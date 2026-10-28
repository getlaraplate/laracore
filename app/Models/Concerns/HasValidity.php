<?php

declare(strict_types=1);

namespace Modules\Core\Models\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use InvalidFormatException;
use Modules\Core\Attributes\FilterScope;
use Modules\Core\Contracts\IValidatableModel;

/**
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-require-implements IValidatableModel
 */
trait HasValidity
{
    protected static $valid_from_column = 'valid_from';

    protected static $valid_to_column = 'valid_to';

    public static function validFromKey(): string
    {
        return static::$valid_from_column;
    }

    public static function validToKey(): string
    {
        return static::$valid_to_column;
    }

    /**
     * The default expiring window for this model, in hours.
     *
     * Read off a static property when the model declares one, so a model can say
     * what "soon" means for its own rows without every caller repeating it.
     */
    public static function expiringWithinHours(): int
    {
        if (property_exists(static::class, 'expiring_within_hours')) {
            $own = static::$expiring_within_hours;

            if (is_int($own) && $own > 0) {
                return $own;
            }
        }

        return max(1, (int) config('core.validity.expiring_within_hours', 48));
    }

    public function initializeHasValidity(): void
    {
        if (! in_array(static::$valid_from_column, $this->fillable, true)) {
            $this->fillable[] = static::$valid_from_column;
        }

        if (! in_array(static::$valid_to_column, $this->fillable, true)) {
            $this->fillable[] = static::$valid_to_column;
        }

        $this->mergeCasts([
            static::$valid_from_column => 'datetime',
            static::$valid_to_column => 'datetime',
        ]);
    }

    /**
     * Check if the content is valid at a given date.
     *
     * @throws InvalidFormatException
     */
    public function isValid(?CarbonInterface $date = null): bool
    {
        if ($this->{static::$valid_from_column} === null) {
            return false;
        }

        $date ??= Date::now();

        return $date->gte($this->{static::$valid_from_column})
            && (! $this->{static::$valid_to_column} || $date->lte($this->{static::$valid_to_column}));
    }

    /**
     * Alias for isValid method.
     */
    public function isPublished(?CarbonInterface $date = null): bool
    {
        return $this->isValid($date);
    }

    /**
     * Check if the content is expired.
     */
    public function isExpired(): bool
    {
        return $this->{static::$valid_to_column} !== null && $this->{static::$valid_to_column} < now();
    }

    /**
     * Check if the content is draft (nor plublished yet).
     */
    public function isDraft(): bool
    {
        return $this->{static::$valid_from_column} === null;
    }

    /**
     * Check if the content is scheduled (published in the future).
     */
    public function isScheduled(): bool
    {
        return $this->{static::$valid_from_column} !== null && $this->{static::$valid_from_column} > now();
    }

    /**
     * Publish the content.
     */
    public function publish(?Carbon $valid_from = null, ?Carbon $valid_to = null): void
    {
        $valid_from ??= now();

        if ($valid_to instanceof Carbon) {
            $min = min($valid_from, $valid_to);
            $max = max($valid_from, $valid_to);
            $valid_from = $min;
            $valid_to = $max;
        }

        $this->{static::$valid_from_column} = $valid_from;
        $this->{static::$valid_to_column} = $valid_to;
    }

    /**
     * Unpublish the content.
     */
    public function unpublish(): void
    {
        $this->{static::$valid_from_column} = null;
        $this->{static::$valid_to_column} = null;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function validityOrdered(Builder $query): Builder
    {
        return $query->orderBy($this->qualifyColumn(static::$valid_from_column), 'desc')/* ->orderBy(static::$valid_to_column, 'desc') */;
    }

    /**
     * Currently valid records.
     *
     * @param  Builder<static>  $query
     *
     * @throws InvalidArgumentException
     *
     * @return Builder<static>
     */
    #[Scope]
    #[FilterScope(client: true)]
    protected function valid(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn(static::$valid_from_column), '<=', now())->where(function ($q): void {
            $q->where($this->qualifyColumn(static::$valid_to_column), '>=', now())->orWhereNull($this->qualifyColumn(static::$valid_to_column));
        });
    }

    /**
     * Records that are valid now but stop being valid within the given window.
     *
     * Neither `valid` nor `expired` answers "what is about to lapse", which is the
     * question a licence or a price list is usually asked. The window is in hours
     * and resolved in three steps, nearest first: the argument, the model's own
     * $expiring_within_hours, then core.validity.expiring_within_hours. A model
     * whose rows live for years is expected to widen it; the default suits a
     * record measured in days.
     *
     * Rows with no valid_to never expire and are never returned.
     *
     * @param  Builder<static>  $query
     * @param  int|null  $within_hours  Window size; null uses the model's own, then the configured default.
     *
     * @throws InvalidArgumentException
     *
     * @return Builder<static>
     */
    #[Scope]
    #[FilterScope(client: true)]
    protected function expiring(Builder $query, ?int $within_hours = null): Builder
    {
        $hours = $within_hours ?? static::expiringWithinHours();

        if ($hours < 1) {
            throw new InvalidArgumentException('The expiring window must be at least one hour.');
        }

        $valid_to = $this->qualifyColumn(static::$valid_to_column);

        return $query->valid()
            ->whereNotNull($valid_to)
            ->where($valid_to, '<=', now()->addHours($hours));
    }

    /**
     * Expired records.
     *
     * @param  Builder<static>  $query
     *
     * @throws InvalidArgumentException
     *
     * @return Builder<static>
     */
    #[Scope]
    #[FilterScope(client: true)]
    protected function expired(Builder $query): Builder
    {
        return $query->expiredAt(now());
    }

    /**
     * Expired records at a given date.
     *
     * @param  Builder<static>  $query
     *
     * @throws InvalidArgumentException
     *
     * @return Builder<static>
     */
    #[Scope]
    #[FilterScope(client: true)]
    protected function expiredAt(Builder $query, CarbonInterface $date): Builder
    {
        // Was expired()->validAt($date), which asked for rows past their validity
        // and still valid on the same date: a contradiction for now(), and for any
        // earlier date a window ("expired between then and now") rather than the
        // state the name promises. Expired at a date is valid_to before that date.
        return $query->withoutGlobalScope('valid')
            ->whereNotNull($this->qualifyColumn(static::$valid_to_column))
            ->where($this->qualifyColumn(static::$valid_to_column), '<', $date);
    }

    /**
     * Filter records by validity on specified date.
     *
     * @param  Builder<static>  $query
     *
     * @throws InvalidArgumentException
     *
     * @return Builder<static>
     */
    #[Scope]
    #[FilterScope(client: true)]
    protected function validAt(Builder $query, CarbonInterface $date): Builder
    {
        return $query->withoutGlobalScope('valid')->withValidityFilter($date);
    }

    /**
     * Filter records by validity on specified date.
     *
     * @param  Builder<static>  $query
     *
     * @throws InvalidArgumentException
     *
     * @return Builder<static>
     */
    #[Scope]
    #[FilterScope(client: true)]
    protected function published(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn(static::$valid_from_column), '<=', now())->where(function ($query): void {
            $query->where($this->qualifyColumn(static::$valid_to_column), '>=', now())->orWhereNull($this->qualifyColumn(static::$valid_to_column));
        });
    }

    /**
     * Filter records by validity on specified date.
     *
     * @param  Builder<static>  $query
     *
     * @throws InvalidArgumentException
     *
     * @return Builder<static>
     */
    #[Scope]
    #[FilterScope(client: true)]
    protected function draft(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn(static::$valid_from_column));
    }

    /**
     * Filter records by validity on specified date.
     *
     * @param  Builder<static>  $query
     *
     * @throws InvalidArgumentException
     *
     * @return Builder<static>
     */
    #[Scope]
    #[FilterScope(client: true)]
    protected function scheduled(Builder $query): Builder
    {
        return $query->whereNotNull($this->qualifyColumn(static::$valid_from_column))->where($this->qualifyColumn(static::$valid_from_column), '>', now());
    }

    /**
     * Filter records by validity on specified date.
     *
     * @param  Builder<static>  $query
     *
     * @throws InvalidArgumentException
     *
     * @return Builder<static>
     */
    #[Scope]
    protected function withValidityFilter(Builder $query, CarbonInterface $date): Builder
    {
        return $query->where($this->qualifyColumn(static::$valid_from_column), '<=', $date)->where(function ($q) use ($date): void {
            $q->where($this->qualifyColumn(static::$valid_to_column), '>=', $date)->orWhereNull($this->qualifyColumn(static::$valid_to_column));
        });
    }
}
