<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Modules\Core\Performance\BootTime;

it('measures up to the instant the framework finished booting, not to now', function (): void {
    $container = new Container;
    $container->instance(BootTime::BOOTED_AT_BINDING, 100.25);

    expect(BootTime::seconds($container, 100.0))->toBe(0.25);
});

it('measures up to now when no boot instant was recorded', function (): void {
    $seconds = BootTime::seconds(new Container, microtime(true) - 0.5);

    expect($seconds)->toBeGreaterThanOrEqual(0.5)->toBeLessThan(5.0);
});

it('reports zero without a process start instant', function (): void {
    $container = new Container;
    $container->instance(BootTime::BOOTED_AT_BINDING, 100.25);

    expect(BootTime::seconds($container, 0.0))->toBe(0.0);
});
