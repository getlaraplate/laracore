<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Modules\Core\Console\SeedCommand;
use Modules\Core\Overrides\Seeder;
use Modules\Core\Tests\Stubs\Seeding\BenchmarkedChildStubSeeder;
use Modules\Core\Tests\Stubs\Seeding\RunBenchmarkRootStubSeeder;

beforeEach(function (): void {
    BenchmarkedChildStubSeeder::$startedOwnBenchmark = [];
});

it('prints one benchmark for the whole db:seed run instead of one per seeder', function (): void {
    config()->set('app.debug', true);

    Artisan::call('db:seed', ['--class' => RunBenchmarkRootStubSeeder::class]);
    $output = Artisan::output();

    expect(mb_substr_count($output, '⚡'))->toBe(1)
        ->and($output)->toContain(SeedCommand::class)
        ->and($output)->toContain('SQL')
        ->and(BenchmarkedChildStubSeeder::$startedOwnBenchmark)->toBe([false, false])
        ->and(app()->bound(Seeder::RUN_BENCHMARK_BINDING))->toBeFalse();
});

it('prints no benchmark when debug is off', function (): void {
    config()->set('app.debug', false);

    Artisan::call('db:seed', ['--class' => RunBenchmarkRootStubSeeder::class]);

    expect(Artisan::output())->not->toContain('⚡')
        ->and(BenchmarkedChildStubSeeder::$startedOwnBenchmark)->toBe([false, false]);
});
