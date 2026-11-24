<?php

declare(strict_types=1);

namespace Modules\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Casts\ActionEnum;
use Modules\Core\Database\Factories\Concerns\HasUniqueFactoryValues;
use Modules\Core\Models\Permission;
use Override;

/**
 * @extends Factory<Permission>
 */
final class PermissionFactory extends Factory
{
    use HasUniqueFactoryValues;

    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = Permission::class;

    /**
     * Define the model's default state.
     *
     * @return array<model-property<Permission>, mixed>
     */
    #[Override]
    public function definition(): array
    {
        $connection = fake()->randomElement(['default', mb_strtolower(fake()->word())]);
        $table = mb_strtolower(fake()->word());
        $action = fake()->randomElement(ActionEnum::cases())->value;

        return [
            'name' => "{$connection}.{$table}.{$action}",
            'guard_name' => (string) config('auth.defaults.guard', 'web'),
            // connection_name and table_name are generated columns, cannot be inserted
        ];
    }
}
