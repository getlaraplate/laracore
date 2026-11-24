<?php

declare(strict_types=1);

namespace Modules\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Database\Factories\Concerns\HasUniqueFactoryValues;
use Modules\Core\Models\CronJob;
use Override;

/**
 * @extends Factory<CronJob>
 */
final class CronJobFactory extends Factory
{
    use HasUniqueFactoryValues;

    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = CronJob::class;

    /**
     * Define the model's default state.
     *
     * @return array<model-property<CronJob>, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'name' => 'inspire-' . uniqid(),
            'command' => 'inspire',
            // 'parameters',
            'schedule' => '0 * * * *',
            'description' => 'Display an inspiring quote',
            'is_active' => false,
        ];
    }
}
