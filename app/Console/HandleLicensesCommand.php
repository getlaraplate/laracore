<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Models\License;
use Modules\Core\Overrides\Command;
use Modules\Core\Services\PerModelSettingResolver;
use Override;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Throwable;

final class HandleLicensesCommand extends Command
{
    #[Override]
    protected $signature = 'auth:licenses';

    #[Override]
    protected $description = 'Renew, add or delete user licenses. <fg=green>(⚡ Modules\Core)</fg=green>';

    /**
     * The status of a group of licenses sharing an expiry: perpetual without one, expired once it has passed,
     * active until then.
     */
    public static function statusOf(?CarbonInterface $valid_to): string
    {
        if (! $valid_to instanceof CarbonInterface) {
            return 'perpetual';
        }

        return today()->greaterThan($valid_to) ? 'expired' : 'active';
    }

    public function handle(): int
    {
        try {
            $connection = (new License)->getConnection();

            return $connection->transaction(function (): int {
                $number = 0;
                $valid_to = null;

                // Ordered by the grouped column: without an order lazy() orders by the key, which is neither
                // grouped nor aggregated and is refused by Db2, PostgreSQL and MySQL with ONLY_FULL_GROUP_BY.
                $licenses_groups = License::query()->groupBy('valid_to')
                    ->select('valid_to')
                    ->selectRaw('count(*) as licenses_count')
                    ->orderBy('valid_to')
                    ->lazy(100);
                $licenses_count = $licenses_groups->reduce(static fn (int $total, License $group): int => $total + self::groupCount($group), 0);

                if ($licenses_groups->isEmpty()) {
                    $this->output->info('No licenses found');
                } else {
                    $this->output->info('Current licenses status');
                    table(
                        ['Status', 'Expiration', 'Licenses Qt.'],
                        $licenses_groups->map(static fn (License $data): array => [
                            self::statusOf($data->valid_to),
                            $data->valid_to?->toDateTimeString() ?? '',
                            (string) self::groupCount($data),
                        ])->values()->all(),
                    );
                }

                $choices = ['list', 'add', 'close'];

                if ($licenses_groups->isNotEmpty()) {
                    $choices[] = 'renew';
                }

                $action = select('Choose an action', $choices);

                if ($action !== 'list') {
                    $number = (int) text(
                        'Number of licenses to ' . $action,
                        validate: fn (string $value): ?string => $this->validationCallback('number', $value, ['number' => 'numeric|min:0']),
                    );

                    if ($number === 0) {
                        return BaseCommand::SUCCESS;
                    }

                    $validations = new License()->getOperationRules('create');

                    $valid_to = text(
                        "Specify an expiring date, otherwise it'll be " . ($action === 'close' ? 'today' : 'perpetual'),
                        'yyyy-mm-dd',
                        validate: fn (string $value): ?string => $this->validationCallback('valid_to', $value, $validations),
                    );
                    $valid_to = $valid_to !== '' && $valid_to !== '0' ? new Carbon($valid_to) : null;
                }

                switch ($action) {
                    case 'renew':
                        $this->renewLicenses($number, $licenses_count, $valid_to);

                        break;
                    case 'add':
                        $this->addLicenses($number, $valid_to);

                        break;
                    case 'close':
                        $this->closeLicenses($number, $valid_to);

                        break;
                    case 'list':
                        $this->listLicenses();

                        break;
                }

                return BaseCommand::SUCCESS;
            });
        } catch (Throwable $throwable) {
            $this->output->error($throwable->getMessage());

            return BaseCommand::FAILURE;
        }
    }

    /**
     * The `count(*)` alias of a group of licenses sharing an expiry: `licenses_count`, since `count` is a reserved
     * word on Db2.
     */
    private static function groupCount(License $group): int
    {
        $count = $group->getAttribute('licenses_count');

        return is_numeric($count) ? (int) $count : 0;
    }

    private function listLicenses(): void
    {
        $licenses = License::with('user')->lazy(100);
        $remapped = [];

        foreach ($licenses as $license) {
            $remapped[] = [$license->id, $license->valid_to, $license->user->name];
        }

        table(['License', 'Expiration', 'User'], $remapped);
        $max_sessions = app(PerModelSettingResolver::class)->value('auth.max_concurrent_sessions', null);
        $this->output->info('Current max sessions available: ' . ($max_sessions === null ? 'unlimited' : (string) $max_sessions));
    }

    private function renewLicenses(int $number, int $licensesCount, ?CarbonInterface $validTo): void
    {
        $updated = License::query()->take($number)->update([
            'valid_from' => today(),
            'valid_to' => $validTo,
        ]);
        $message = sprintf('Renewed %d licenses', $updated);
        $this->output->info($message);
        Log::info($message);

        if ($licensesCount > $number) {
            $closed = License::query()->offset($number)->update([
                'valid_to' => $validTo ?? today(),
            ]);
            $message = sprintf('Closed %d licenses', $closed);
            $this->output->info($message);
            Log::info($message);
        } elseif ($licensesCount < $number) {
            $difference = $number - $licensesCount;

            if (confirm(sprintf('%d licenses found. Do you confirm %d licenses creation?', $licensesCount, $difference))) {
                License::factory()->count($difference)->create();
                $message = sprintf('Added %d new licenses', $difference);
                $this->output->info($message);
                Log::info($message);
            }
        }
    }

    private function addLicenses(int $number, ?CarbonInterface $validTo): void
    {
        $query = License::expired()->take($number);
        $expired = $query->count();

        if ($expired && confirm(sprintf('Found %d expired licenses. Would you renew them, before creating new ones?', $expired))) {
            $query->update([
                'valid_to' => $validTo ?? null,
            ]);
            $message = sprintf('Renewed %d licenses', $expired);
            $this->output->info($message);
            Log::info($message);
            $number -= $expired;
        }

        if ($number !== 0) {
            License::factory($number)->create([
                'valid_to' => $validTo ?? today(),
            ]);
            $message = sprintf('Added %s new licenses', $number);
            $this->output->info($message);
            Log::info($message);
        }
    }

    private function closeLicenses(int $number, ?CarbonInterface $validTo): void
    {
        $query = License::query()->take($number);
        $closed = $query->count();
        $query->update([
            'valid_to' => $validTo ?? today(),
        ]);
        $message = sprintf('Closed %d licenses', $closed);
        $this->output->info($message);
        Log::info($message);
    }

    private function validationCallback(string $attribute, string $value, array $validations): ?string
    {
        if (! array_key_exists($attribute, $validations)) {
            return null;
        }

        $validator = Validator::make([$attribute => $value], array_filter($validations, fn (string|int $k): bool => $k === $attribute, ARRAY_FILTER_USE_KEY))->stopOnFirstFailure(true);

        if (! $validator->passes()) {
            return $validator->messages()->first();
        }

        return null;
    }
}
