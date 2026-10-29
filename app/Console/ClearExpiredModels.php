<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use function class_uses_trait;
use function config;
use function models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Overrides\Command;
use Modules\Core\Versioning\HardDeleteCoordinator;
use Override;

final class ClearExpiredModels extends Command
{
    /**
     * The name and signature of the console command.
     */
    #[Override]
    protected $signature = 'model:clear-expired';

    /**
     * The console command description.
     */
    #[Override]
    protected $description = 'Clear soft deleted models that have expired <fg=green>(⚡ Modules\Core)</fg=green>';

    /**
     * Execute the console command. Each expired record is deleted on its own, through the coordinator, so its events
     * and its snapshot set are those of any hard delete (D29); `chunkById` keeps the pages stable while rows leave.
     */
    public function handle(HardDeleteCoordinator $coordinator): void
    {
        $expirationDays = config('core.soft_deletes.expiration_days');

        if ($expirationDays) {
            foreach (models() as $model) {
                if (class_uses_trait($model, SoftDeletes::class)) {
                    $model::onlyTrashed()
                        ->where('deleted_at', '<', now()->subDays($expirationDays))
                        ->chunkById(100, static function (iterable $records) use ($coordinator): void {
                            foreach ($records as $record) {
                                /** @var Model $record */
                                $coordinator->purge($record);
                            }
                        });
                }
            }
        }
    }
}
