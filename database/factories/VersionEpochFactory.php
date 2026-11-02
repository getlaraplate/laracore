<?php

declare(strict_types=1);

namespace Modules\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\VersionEpoch;
use Modules\Core\Models\VersionSet;
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
            'root_type' => VersionSet::class,
            'fingerprint' => fake()->sha256(),
            'definition' => ['columns' => [], 'translation_columns' => [], 'relations' => []],
            'first_seen_at' => now(),
        ];
    }
}
