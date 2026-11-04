<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Facade;

/**
 * A full-application test run earlier in the same process ends with its application flushed but still
 * global, and with facades such as App resolved to it: Laravel clears them only in its own setUp. The
 * lightweight TestCase must not inherit that state.
 */
it('leaves behind what a finished full-application test leaves', function (): void {
    $flushed = new Application(dirname(__DIR__, 3));
    Facade::setFacadeApplication($flushed);
    App::getFacadeRoot();
    $flushed->flush();

    expect(Container::getInstance()->bound('config'))->toBeFalse();
});

it('starts the next lightweight test with its own container and fresh facades', function (): void {
    expect(Container::getInstance()->bound('config'))->toBeTrue()
        ->and(config('app.locale'))->toBe('en')
        ->and(App::getLocale())->toBe('en');
})->depends('it leaves behind what a finished full-application test leaves');
