<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Seeding;

use Modules\Core\Overrides\Seeder;
use Modules\Core\Tests\Stubs\Seeding\RunBenchmarkRootStubSeeder as RootSeeder;

/**
 * Test double for SeedingRunBenchmarkTest: a Core seeder that records whether it started a
 * benchmark of its own, and runs one query so the run benchmark has something to count.
 *
 * @see RootSeeder
 */
final class BenchmarkedChildStubSeeder extends Seeder
{
    /**
     * @var list<bool>
     */
    public static array $startedOwnBenchmark = [];

    public function run(): void
    {
        self::$startedOwnBenchmark[] = $this->benchmarkStartTime !== null;

        $this->db->connection()->select('select 1');
    }
}
