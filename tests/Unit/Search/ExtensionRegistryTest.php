<?php

declare(strict_types=1);

use Modules\Core\Search\Extensions\ExtensionRegistry;
use Modules\Core\Tests\Stubs\Extensions\ExtensionHostStub;
use Modules\Core\Tests\Stubs\Extensions\FirstExtenderStub;
use Modules\Core\Tests\Stubs\Extensions\SecondExtenderStub;

/*
 * The extenders of a host, by alias, kept per host: an alias registered for one host is unknown to another.
 * Spec 2026-11-17-extended-entity-search-design.md, XS13.
 */

it('resolves an alias registered for a host, and only for that host', function (): void {
    $registry = new ExtensionRegistry();
    $registry->register(ExtensionHostStub::class, FirstExtenderStub::ALIAS, FirstExtenderStub::class);

    expect($registry->has(ExtensionHostStub::class, FirstExtenderStub::ALIAS))->toBeTrue()
        ->and($registry->resolve(ExtensionHostStub::class, FirstExtenderStub::ALIAS))->toBe(FirstExtenderStub::class)
        ->and($registry->has('Another\\Host', FirstExtenderStub::ALIAS))->toBeFalse()
        ->and($registry->extenders('Another\\Host'))->toBe([]);
});

it('lists the extenders of a host in registration order', function (): void {
    $registry = new ExtensionRegistry();
    $registry->register(ExtensionHostStub::class, SecondExtenderStub::ALIAS, SecondExtenderStub::class);
    $registry->register(ExtensionHostStub::class, FirstExtenderStub::ALIAS, FirstExtenderStub::class);

    expect($registry->extenders(ExtensionHostStub::class))->toBe([
        SecondExtenderStub::ALIAS => SecondExtenderStub::class,
        FirstExtenderStub::ALIAS => FirstExtenderStub::class,
    ])->and($registry->aliases(ExtensionHostStub::class))->toBe([SecondExtenderStub::ALIAS, FirstExtenderStub::ALIAS]);
});

it('refuses an alias that is not registered for the host', function (): void {
    (new ExtensionRegistry())->resolve(ExtensionHostStub::class, 'core.unknown');
})->throws(InvalidArgumentException::class, 'core.unknown');

it('refuses to register a class that is not an extender', function (): void {
    (new ExtensionRegistry())->register(ExtensionHostStub::class, 'core.host', ExtensionHostStub::class);
})->throws(InvalidArgumentException::class, 'IExtendsModel');

it('ships no extender: modules register theirs at boot', function (): void {
    expect((new ExtensionRegistry())->extenders(ExtensionHostStub::class))->toBe([])
        ->and((new ExtensionRegistry())->aliases(ExtensionHostStub::class))->toBe([]);
});
