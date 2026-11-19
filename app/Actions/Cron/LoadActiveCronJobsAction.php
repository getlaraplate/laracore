<?php

declare(strict_types=1);

namespace Modules\Core\Actions\Cron;

use Cron\CronExpression;
use Exception;
use Modules\Core\Models\CronJob;

/**
 * Reads the active cron rows as the list the scheduler and the cron cache entry share.
 */
final readonly class LoadActiveCronJobsAction
{
    /**
     * @return array<int, array{command: string, schedule: string, parameters: array<int|string, mixed>, on_one_server: bool, without_overlapping: bool}>
     */
    public function __invoke(): array
    {
        try {
            // Live connection check, not SchemaInspector: its process-level memoization survives across Pest tests
            // while :memory: SQLite is wiped. Query the model's own connection to preserve connection affinity.
            $cron_model = new CronJob;

            if (! $cron_model->getConnection()->getSchemaBuilder()->hasTable($cron_model->getTable())) {
                return [];
            }

            $cron_jobs = CronJob::query()
                ->active()
                ->select(['command', 'schedule', 'parameters', 'on_one_server', 'without_overlapping'])
                ->get();

            $normalized = [];

            foreach ($cron_jobs as $cron_job) {
                $command = $cron_job->getAttribute('command');
                $schedule = $cron_job->getAttribute('schedule');

                if ($schedule instanceof CronExpression) {
                    $schedule = $schedule->getExpression();
                }

                if (! is_scalar($command) || ! is_scalar($schedule)) {
                    continue;
                }

                $normalized[] = [
                    'command' => (string) $command,
                    'schedule' => (string) $schedule,
                    'parameters' => self::parameters($cron_job->getAttribute('parameters')),
                    'on_one_server' => (bool) $cron_job->getAttribute('on_one_server'),
                    'without_overlapping' => (bool) $cron_job->getAttribute('without_overlapping'),
                ];
            }

            return $normalized;
        } catch (Exception $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * The parameters of a cron row as the array Laravel's scheduler takes: the column holds JSON, and a
     * value written as a JSON string comes back as that string.
     *
     * @return array<int|string, mixed>
     */
    public static function parameters(mixed $parameters): array
    {
        if (is_string($parameters)) {
            $parameters = json_decode($parameters, true);
        }

        return is_array($parameters) ? $parameters : [];
    }
}
