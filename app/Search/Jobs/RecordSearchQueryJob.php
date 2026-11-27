<?php

declare(strict_types=1);

namespace Modules\Core\Search\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Modules\Core\Search\Services\SearchQueryRegistry;

/**
 * Adds one search to the application search registry. It carries the normalized query only: no user, IP or session.
 */
final class RecordSearchQueryJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * @param  string  $day  The day of the search, as `Y-m-d`.
     */
    public function __construct(
        public readonly string $model,
        public readonly string $query,
        public readonly string $locale,
        public readonly int $hits,
        public readonly string $day,
    ) {}

    public function handle(SearchQueryRegistry $registry): void
    {
        $registry->record($this->model, $this->query, $this->locale, $this->hits, CarbonImmutable::parse($this->day));
    }
}
