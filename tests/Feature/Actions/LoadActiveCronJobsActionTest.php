<?php

declare(strict_types=1);

use Modules\Core\Actions\Cron\LoadActiveCronJobsAction;
use Modules\Core\Models\CronJob;

it('lists only the active cron rows with parameters and both flags', function (): void {
    CronJob::factory()->create(['command' => 'cron-probe:prune', 'schedule' => '*/5 * * * *', 'is_active' => true]);
    CronJob::factory()->create(['command' => 'cron-probe:off', 'is_active' => false]);

    $crons = app(LoadActiveCronJobsAction::class)();

    expect($crons)->toHaveCount(1)
        ->and($crons[0])->toMatchArray(['command' => 'cron-probe:prune', 'schedule' => '*/5 * * * *', 'on_one_server' => true, 'without_overlapping' => true])
        ->and($crons[0]['parameters'])->toBeArray();
});

it('reads parameters stored as a JSON string', function (): void {
    expect(LoadActiveCronJobsAction::parameters('["--all"]'))->toBe(['--all'])
        ->and(LoadActiveCronJobsAction::parameters('nonsense'))->toBe([])
        ->and(LoadActiveCronJobsAction::parameters(null))->toBe([]);
});
