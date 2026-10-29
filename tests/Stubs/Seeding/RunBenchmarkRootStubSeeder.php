<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Seeding;

use Illuminate\Database\Seeder;

/**
 * Test double for SeedingRunBenchmarkTest: the root a `db:seed --class` runs, calling two
 * Core seeders as the orchestrator does.
 */
final class RunBenchmarkRootStubSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([BenchmarkedChildStubSeeder::class, BenchmarkedChildStubSeeder::class]);
    }
}
