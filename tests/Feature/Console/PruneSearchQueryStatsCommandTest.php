<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\Core\Models\SearchQueryStat;

function searchStatOnDay(string $query, DateTimeInterface $day): SearchQueryStat
{
    return SearchQueryStat::query()->create([
        'query' => $query,
        'locale' => 'en',
        'model' => 'cms_contents',
        'day' => $day->format('Y-m-d'),
        'searches' => 1,
        'low_result_searches' => 0,
        'result_sum' => 3,
    ]);
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-27 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('deletes rows older than the retention window and keeps the rest', function (): void {
    config()->set('core.search.registry.retention_days', 90);

    $expired = searchStatOnDay('expired', now()->subDays(91));
    $boundary = searchStatOnDay('boundary', now()->subDays(90));
    $today = searchStatOnDay('today', now());

    $this->artisan('search:prune-registry')->assertSuccessful();

    expect(SearchQueryStat::query()->whereKey($expired->id)->exists())->toBeFalse()
        ->and(SearchQueryStat::query()->whereKey($boundary->id)->exists())->toBeTrue()
        ->and(SearchQueryStat::query()->whereKey($today->id)->exists())->toBeTrue();
});

it('prunes even when the registry is switched off', function (): void {
    config()->set('core.search.registry.enabled', false);
    config()->set('core.search.registry.retention_days', 30);

    searchStatOnDay('old', now()->subDays(31));

    $this->artisan('search:prune-registry')->assertSuccessful();

    expect(SearchQueryStat::query()->count())->toBe(0);
});

it('keeps everything when the retention window is not positive', function (): void {
    config()->set('core.search.registry.retention_days', 0);

    searchStatOnDay('old', now()->subDays(400));

    $this->artisan('search:prune-registry')->assertSuccessful();

    expect(SearchQueryStat::query()->count())->toBe(1);
});
