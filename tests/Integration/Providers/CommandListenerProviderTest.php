<?php

declare(strict_types=1);

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Providers\CommandListenerProvider;
use Modules\Core\Tests\Stubs\Cache\SpyLabelIndex;

beforeEach(function (): void {
    $this->provider = new CommandListenerProvider(app());
});

it('provides returns empty array', function (): void {
    expect($this->provider->provides())->toBe([]);
});

it('register adds scope and locale to context', function (): void {
    $this->provider->register();

    expect(Context::get('scope'))->toBeIn(['console', 'web']);
    expect(Context::get('locale'))->toBeString();
});

it('boot listens to MigrationsEnded and flushes caches', function (): void {
    $called = false;
    Event::listen(MigrationsEnded::class, function () use (&$called): void {
        $called = true;
    });

    $this->provider->boot();
    Event::dispatch(new MigrationsEnded('run', []));

    expect($called)->toBeTrue();
});

it('boot flushes the inspector label after migrations', function (): void {
    $index = new SpyLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);

    $this->provider->boot();
    Event::dispatch(new MigrationsEnded('run', []));

    expect($index->flushes)->toContain(['inspector']);
});
