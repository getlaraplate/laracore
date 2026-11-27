<?php

declare(strict_types=1);

namespace Modules\Core\Overrides;

use Illuminate\Queue\Console\MonitorCommand as BaseMonitorCommand;
use Illuminate\Support\Collection;
use Modules\Core\Console\Concerns\HasBenchmark;
use Override;

/**
 * Adds `--all` to `queue:monitor` so every queue Horizon is configured to
 * process can be checked in one call, instead of listing `connection:queue`
 * pairs by hand. Everything else delegates to the framework command.
 */
final class QueueMonitorCommand extends BaseMonitorCommand
{
    use HasBenchmark;

    /**
     * @var string
     */
    protected $signature = 'queue:monitor
                            {queues? : The names of the queues to monitor (connection:queue, comma-separated)}
                            {--max=1000 : The maximum number of jobs that can be on the queue before an event is dispatched}
                            {--json : Output the queue size as JSON}
                            {--all : Monitor every queue Horizon is configured to process}';

    /**
     * @var string
     */
    protected $description = 'Monitor queue sizes; --all covers every Horizon queue <fg=green>(⚡ Modules\Core)</fg=green>';

    /**
     * Laravel's MonitorCommand::handle() declares no return type and returns nothing,
     * so an override that returns an exit code has to say so: without the `: int`,
     * every `return self::FAILURE` here is read as returning a value from a void method.
     */
    #[Override]
    public function handle(): int
    {
        $benchmark = ! app()->runningUnitTests();

        if ($benchmark) {
            $this->startBenchmark();
        }

        try {
            if ($this->option('all')) {
                $this->input->setArgument('queues', $this->configuredQueues());
            }

            $queues = $this->argument('queues');

            if (! is_string($queues) || mb_trim($queues) === '') {
                $this->components->error('Provide queue names (connection:queue,...) or pass --all.');

                return self::FAILURE;
            }

            // The parent returns nothing; the exit code is this command's to report.
            parent::handle();

            return self::SUCCESS;
        } finally {
            if ($benchmark) {
                $this->endBenchmark();
            }
        }
    }

    /**
     * The default connection prefixed to every queue Horizon processes
     * (union of `horizon.defaults` and the active environment block), falling
     * back to the default connection's own queue when Horizon has none.
     */
    public function configuredQueues(): string
    {
        $default_connection = config('queue.default', 'redis');
        $connection = is_string($default_connection) ? $default_connection : 'redis';

        // config() returns mixed, and Collection wants an array: a non-array value here
        // means the Horizon config is malformed, and an empty list is the honest reading.
        $horizon_defaults = config('horizon.defaults', []);
        $horizon_environment = config('horizon.environments.' . app()->environment(), []);

        $queues = (new Collection(is_array($horizon_defaults) ? $horizon_defaults : []))->pluck('queue')
            ->merge((new Collection(is_array($horizon_environment) ? $horizon_environment : []))->pluck('queue'))
            ->flatten()
            ->filter(fn (mixed $queue): bool => is_string($queue) && $queue !== '')
            ->unique()
            ->values();

        if ($queues->isEmpty()) {
            $connection_queue = config("queue.connections.{$connection}.queue", 'default');
            $queues = new Collection([is_string($connection_queue) ? $connection_queue : 'default']);
        }

        return $queues->map(fn (string $queue): string => "{$connection}:{$queue}")->implode(',');
    }
}
