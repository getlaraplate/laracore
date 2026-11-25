<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Helpers\BatchSeeder;

afterEach(function (): void {
    app()->forgetInstance(BatchSeeder::SCALE_CONTAINER_KEY);
});

function makeScaleSeeder(): BatchSeeder
{
    return new class(app(DatabaseManager::class)) extends BatchSeeder
    {
        // Pest tears the app down before object destruction; the parent
        // destructor calls config() and would fatal, so it is neutralised here.
        public function __destruct() {}

        protected function execute(): void {}
    };
}

function publishScale(float $factor): void
{
    app()->instance(BatchSeeder::SCALE_CONTAINER_KEY, $factor);
}

function invokeScale(BatchSeeder $seeder, string $method, mixed ...$args): mixed
{
    $method = new ReflectionMethod(BatchSeeder::class, $method);

    return $method->invoke($seeder, ...$args);
}

it('uses full scale when no scale is published', function (): void {
    app()->forgetInstance(BatchSeeder::SCALE_CONTAINER_KEY);

    expect(invokeScale(makeScaleSeeder(), 'resolveSeedScale'))->toBe(1.0);
});

it('reads the published scale from the container', function (): void {
    publishScale(0.1);

    expect(invokeScale(makeScaleSeeder(), 'resolveSeedScale'))->toBe(0.1);
});

it('scales a target count by the published factor', function (): void {
    publishScale(0.5);

    expect(invokeScale(makeScaleSeeder(), 'scaleTargetCount', 10))->toBe(5);
});

it('leaves a target count untouched at full scale', function (): void {
    publishScale(1.0);

    expect(invokeScale(makeScaleSeeder(), 'scaleTargetCount', 6_000))->toBe(6_000);
});

it('floors a positive scaled target to at least one record', function (): void {
    publishScale(0.1);

    // 3 * 0.1 = 0.3, which rounds to 0; the floor keeps one record so
    // dependent seeders never face an unexpectedly empty table.
    expect(invokeScale(makeScaleSeeder(), 'scaleTargetCount', 3))->toBe(1);
});

it('keeps a zero target at zero', function (): void {
    publishScale(0.1);

    expect(invokeScale(makeScaleSeeder(), 'scaleTargetCount', 0))->toBe(0);
});

it('hands its subclasses the console command that runs it', function (): void {
    $command = Illuminate\Support\Facades\Artisan::all()['db:seed'];
    $seeder = makeScaleSeeder();
    $seeder->setCommand($command);

    expect(invokeScale($seeder, 'console'))->toBe($command);
});

it('refuses to run outside a console command', function (): void {
    expect(fn (): mixed => invokeScale(makeScaleSeeder(), 'console'))
        ->toThrow(LogicException::class, 'runs only from a console command');
});
