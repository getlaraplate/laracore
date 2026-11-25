<?php

declare(strict_types=1);

namespace Modules\Core\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Helpers\BatchSeeder;
use Modules\Core\Models\License;

final class DevCoreDatabaseSeeder extends BatchSeeder
{
    private const TARGET_COUNT_USERS = 6_000;

    private const TARGET_COUNT_LICENSES = 2_000;

    protected function execute(): void
    {
        Artisan::call('module:seed', ['module' => 'Core', '--force' => $this->console()->option('force')], outputBuffer: $this->console()->getOutput());

        $this->withoutModelVersioning(
            [User::class, License::class],
            fn () => Model::unguarded(function (): void {
                $this->seedUsers();
                $this->seedLicenses();
            }),
        );

        Artisan::call('cache:clear');
    }

    private function seedUsers(): void
    {
        $this->createInParallelBatches(User::class, self::TARGET_COUNT_USERS);
    }

    private function seedLicenses(): void
    {
        $this->createInParallelBatches(License::class, self::TARGET_COUNT_LICENSES);
    }
}
