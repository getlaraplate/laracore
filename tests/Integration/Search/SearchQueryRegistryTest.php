<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\SearchQueryStat;
use Modules\Core\Search\Services\SearchQueryRegistry;

function search_registry(): SearchQueryRegistry
{
    return resolve(SearchQueryRegistry::class);
}

it('normalizes case, spaces and request words', function (): void {
    expect(search_registry()->normalize('  Bici   Elettrica ', 'it'))->toBe('bici elettrica')
        ->and(search_registry()->normalize('articoli su bici elettrica', 'it'))->toBe('bici elettrica')
        ->and(search_registry()->normalize('NATO vertice', 'it'))->toBe('nato vertice');
});

it('refuses queries holding an email, a number, an identifier or a UUID', function (string $query): void {
    expect(search_registry()->normalize($query, 'it'))->toBeNull();
})->with([
    'email' => 'mario rossi mario@example.com',
    'number' => 'ordine 12345',
    'identifier' => 'ABC-123',
    'uuid' => '0b9a3f0e-6f4d-4c2a-9a1e-3c1f5d7a8b90',
    'empty' => '   ',
    'too long' => str_repeat('a', 256),
]);

it('aggregates searches per day', function (): void {
    config()->set('core.search.registry.low_results_threshold', 5);
    $day = CarbonImmutable::parse('2026-11-27');

    search_registry()->record('cms_articles', 'bici', 'it', 2, $day);
    search_registry()->record('cms_articles', 'bici', 'it', 8, $day);

    $row = SearchQueryStat::query()->sole();

    expect($row->searches)->toBe(2)
        ->and($row->low_result_searches)->toBe(1)
        ->and($row->result_sum)->toBe(10);

    search_registry()->record('cms_articles', 'bici', 'it', 1, $day->addDay());

    expect(SearchQueryStat::query()->count())->toBe(2);
});

it('seeds the registry settings, off by default', function (): void {
    $definitions = collect(Modules\Core\Database\Seeders\CoreDatabaseSeeder::runtimeSettingDefinitions())->keyBy('name');

    expect($definitions->get('search.registry.enabled')['value'])->toBeFalse()
        ->and($definitions->get('search.registry.retention_days')['value'])->toBe(90)
        ->and($definitions->get('search.registry.low_results_threshold')['value'])->toBe(5);
});
