<?php

declare(strict_types=1);

namespace Modules\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\VersionEpoch;
use Override;

/**
 * @extends Factory<VersionEpoch>
 */
final class VersionEpochFactory extends Factory
{
    /**
     * @var class-string<VersionEpoch>
     */
    protected $model = VersionEpoch::class;

    #[Override]
    public function definition(): array
    {
        return [
            'root_type' => 'Tests\\EpochRoot\\' . fake()->unique()->word(),
            'root_table' => 'core_test_epoch_roots',
            'fingerprint' => fake()->sha256(),
            'definition' => ['columns' => [], 'translation_columns' => [], 'relations' => []],
            'first_seen_at' => now(),
        ];
    }
}
