<?php

declare(strict_types=1);

use Modules\Core\Casts\SearchMode;
use Modules\Core\Search\Extensions\ExtensionFieldConflict;
use Modules\Core\Search\Schema\FieldType;
use Modules\Core\Tests\Stubs\Extensions\ExtensionHostStub;
use Modules\Core\Tests\Stubs\Extensions\FirstExtenderStub;
use Modules\Core\Tests\Stubs\Extensions\SecondExtenderStub;
use Modules\Core\Tests\Support\DefaultFilteredSearchFixture;
use Modules\Core\Tests\Support\ExtensionFixture;
use Modules\Core\Tests\Support\FakeVectorProfile;

/*
 * The generic host side of the extension seam (spec 2026-11-17-extended-entity-search-design.md, XS11, XS13, XS16):
 * a host hides the rows its extenders own, opens to them on request, upcasts a page to its extenders, composes the
 * `extension` part of its search document and mapping, and keeps extended rows out of every search path.
 */

beforeEach(function (): void {
    FakeVectorProfile::none();
    config()->set('permission.roles.superadmin', 'superadmin');
    ExtensionFixture::setUp();
});

afterEach(function (): void {
    ExtensionFixture::tearDown();
});

it('hides the rows extenders own, and opens to them on request', function (): void {
    $plain = ExtensionFixture::row('plain');
    $extended = ExtensionFixture::row('extended', FirstExtenderStub::ALIAS, 'gold');

    expect(ExtensionHostStub::query()->pluck('id')->all())->toBe([$plain->id])
        ->and(ExtensionHostStub::withExtended()->orderBy('id')->pluck('id')->all())->toBe([$plain->id, $extended->id])
        ->and((new ExtensionHostStub)->queryWithExtensions()->orderBy('id')->pluck('id')->all())->toBe([$plain->id, $extended->id]);
});

it('upcasts a page to its extenders with one query per alias, keeping the order', function (): void {
    $plain = ExtensionFixture::row('plain');
    $first = ExtensionFixture::row('first', FirstExtenderStub::ALIAS, 'gold');
    $second = ExtensionFixture::row('second', SecondExtenderStub::ALIAS, 'silver');
    $other = ExtensionFixture::row('other');

    $host = new ExtensionHostStub;
    $rows = $host->queryWithExtensions()->orderBy('id')->get();
    $connection = $host->getConnection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $page = $host->upcastExtensions($rows);

    $queries = count($connection->getQueryLog());
    $connection->disableQueryLog();

    expect($queries)->toBe(2)
        ->and($page->map(static fn ($row): string => $row::class)->all())->toBe([ExtensionHostStub::class, FirstExtenderStub::class, SecondExtenderStub::class, ExtensionHostStub::class])
        ->and($page[0]->id)->toBe($plain->id)
        ->and($page[1]->host_id)->toBe($first->id)
        ->and($page[1]->getRelation('host'))->toBe($rows[1])
        ->and($page[2]->getRelation('host'))->toBe($rows[2])
        ->and($page[2]->kind)->toBe('silver')
        ->and($page[3]->id)->toBe($other->id);
});

it('refuses to upcast a row whose alias is not registered', function (): void {
    ExtensionFixture::row('unknown', 'core.unknown');
    $host = new ExtensionHostStub;

    $host->upcastExtensions($host->queryWithExtensions()->get());
})->throws(InvalidArgumentException::class, 'core.unknown');

it('refuses to upcast an extended row with no extender row', function (): void {
    $host = new ExtensionHostStub;
    $host->forceFill(['title' => 'orphan', 'extension_type' => FirstExtenderStub::ALIAS])->save();

    $host->upcastExtensions($host->queryWithExtensions()->get());
})->throws(RuntimeException::class, 'has no extender row');

it('includes extended rows in the bulk import query', function (): void {
    $plain = ExtensionFixture::row('plain');
    $extended = ExtensionFixture::row('extended', FirstExtenderStub::ALIAS);

    $imported = (new ExtensionHostStub)->makeAllSearchableUsing(ExtensionHostStub::query())->orderBy('id')->pluck('id')->all();

    expect($imported)->toBe([$plain->id, $extended->id]);
});

it('writes the extension section of an extended row and none for a plain one', function (): void {
    $extended = ExtensionFixture::row('extended', FirstExtenderStub::ALIAS, 'gold');
    $plain = ExtensionFixture::row('plain');
    $unknown = ExtensionFixture::row('unknown', 'core.unknown');

    expect($extended->extensionSearchDocument())->toBe(['type' => FirstExtenderStub::ALIAS, 'kind' => 'gold'])
        ->and($plain->extensionSearchDocument())->toBeNull()
        ->and($unknown->extensionSearchDocument())->toBeNull();
});

it('composes one mapping entry for a field two extenders declare with the same definition', function (): void {
    SecondExtenderStub::$mapping = [
        'kind' => ['type' => FieldType::Keyword, 'filterable' => true],
        'colour' => FieldType::Keyword,
    ];

    expect((new ExtensionHostStub)->extensionSearchMapping())->toBe([
        'type' => ['type' => FieldType::Keyword, 'filterable' => true],
        'kind' => ['type' => FieldType::Keyword, 'filterable' => true],
        'colour' => FieldType::Keyword,
    ]);
});

it('refuses a field two extenders declare with different definitions, naming both', function (array $second): void {
    SecondExtenderStub::$mapping = ['kind' => $second];

    expect(fn (): array => (new ExtensionHostStub)->extensionSearchMapping())->toThrow(
        ExtensionFieldConflict::class,
        'XS16',
    );

    try {
        (new ExtensionHostStub)->extensionSearchMapping();
    } catch (ExtensionFieldConflict $conflict) {
        expect($conflict->getMessage())->toContain('"kind"', FirstExtenderStub::ALIAS, SecondExtenderStub::ALIAS);
    }
})->with([
    'another type' => [['type' => FieldType::Integer, 'filterable' => true]],
    'the same type, not filterable' => [['type' => FieldType::Keyword]],
]);

it('refuses an extender field that redefines the alias type of the host', function (): void {
    SecondExtenderStub::$mapping = ['type' => FieldType::Text];

    (new ExtensionHostStub)->extensionSearchMapping();
})->throws(ExtensionFieldConflict::class, 'the host');

it('keeps extended rows out of every search path with no default search filter declared', function (SearchMode $mode): void {
    $plain = [ExtensionFixture::row('needle one')->id, ExtensionFixture::row('needle two')->id];
    ExtensionFixture::row('needle three', FirstExtenderStub::ALIAS);
    ExtensionFixture::row('needle four', SecondExtenderStub::ALIAS);

    $ids = DefaultFilteredSearchFixture::search($mode, 2, null, null, new ExtensionHostStub);
    sort($ids);

    // The engine lists the newest rows first: without the exclusion at the engine the extended rows would take the
    // page and be dropped at reload.
    expect($ids)->toBe($plain);
})->with([SearchMode::Basic, SearchMode::Orchestrated, SearchMode::Auto]);
