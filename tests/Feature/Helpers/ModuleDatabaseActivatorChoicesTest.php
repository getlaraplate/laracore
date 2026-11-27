<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Modules\Core\Helpers\ModuleDatabaseActivator;
use Modules\Core\Models\Module;

beforeEach(function (): void {
    config()->set('modules.cache.enabled', false);
    Cache::forget('modules_db_activator_statuses');
});

it('creates an active row for every module on disk on first read', function (): void {
    Module::query()->delete();

    new ModuleDatabaseActivator(app())->hasStatus('Core', true);

    expect(Module::query()->pluck('name')->all())->toEqualCanonicalizing(ModuleDatabaseActivator::getAllModulesNames())
        ->and(Module::query()->where('is_active', false)->exists())->toBeFalse();
});

it('adds only the missing modules and keeps the state of the existing rows', function (): void {
    Module::query()->delete();
    Module::query()->forceCreate(['name' => 'Core', 'is_active' => true]);
    Module::query()->forceCreate(['name' => 'CMS', 'is_active' => false, 'url' => 'https://cms.test']);

    ModuleDatabaseActivator::syncModules();

    $cms = Module::query()->where('name', 'CMS')->sole();

    expect(Module::query()->pluck('name')->all())->toEqualCanonicalizing(ModuleDatabaseActivator::getAllModulesNames())
        ->and($cms->is_active)->toBeFalse()
        ->and($cms->url)->toBe('https://cms.test');
});

it('toggles a module with a single row update and reports its status', function (): void {
    $activator = new ModuleDatabaseActivator(app());

    $activator->setActiveByName('CMS', false);

    expect(Module::query()->where('name', 'CMS')->value('is_active'))->toBeFalse()
        ->and($activator->hasStatus('CMS', false))->toBeTrue();

    $activator->setActiveByName('CMS', true);

    expect($activator->hasStatus('CMS', true))->toBeTrue();
});

it('never disables or deletes Core', function (): void {
    $activator = new ModuleDatabaseActivator(app());

    expect(fn () => $activator->setActiveByName('Core', false))->toThrow(LogicException::class);

    $core = Module::query()->where('name', 'Core')->sole();

    expect(fn () => $core->update(['url' => null]) && $core->deactivate())->toThrow(LogicException::class)
        ->and(fn () => $core->delete())->toThrow(LogicException::class)
        ->and(Module::query()->where('name', 'Core')->value('is_active'))->toBeTrue();
});

it('flushes the cached statuses when a module is saved through Eloquent', function (): void {
    config()->set('modules.cache.enabled', true);
    config()->set('modules.cache.driver', config('cache.default'));

    $first = new ModuleDatabaseActivator(app());
    expect($first->hasStatus('CMS', true))->toBeTrue();

    Module::query()->where('name', 'CMS')->sole()->deactivate();

    expect(new ModuleDatabaseActivator(app())->hasStatus('CMS', false))->toBeTrue();
});

it('remembers that the modules table exists for the activator cache lifetime, not for good', function (): void {
    $written = [];
    Illuminate\Support\Facades\Event::listen(
        Illuminate\Cache\Events\KeyWritten::class,
        static function (Illuminate\Cache\Events\KeyWritten $event) use (&$written): void {
            $written[$event->key] = $event->seconds;
        },
    );
    Cache::forget('modules_db_activator_checked');

    expect(ModuleDatabaseActivator::checkModulesTable())->toBeTrue()
        ->and($written)->toHaveKey('modules_db_activator_checked')
        ->and($written['modules_db_activator_checked'])->toBeInt()->toBeGreaterThan(0);
});
