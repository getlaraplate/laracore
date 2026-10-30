<?php

declare(strict_types=1);

use Modules\Core\Search\Engines\ElasticsearchEngine;
use Modules\Core\Services\ElasticsearchService;
use Modules\Core\Tests\Stubs\Search\FullMappingStubModel;
use Modules\Core\Tests\Stubs\Search\NestedVectorLocaleStubModel;
use Modules\Core\Tests\Stubs\Search\VectorMappingStubModel;
use Modules\Core\Tests\Support\FakeVectorProfile;

/**
 * ES-gated: exercises the real ElasticsearchEngine::createIndex against a live
 * cluster using a dedicated throwaway index. Skips when Elasticsearch is not the
 * configured engine or is unreachable, so it is a no-op in CI without ES.
 */
it('applies the dense_vector embedding mapping to Elasticsearch on index creation', function (): void {
    $engine = (new VectorMappingStubModel)->searchableUsing();

    if (! $engine instanceof ElasticsearchEngine) {
        $this->markTestSkipped('Elasticsearch is not the configured scout engine.');
    }

    try {
        $engine->health();
    } catch (Throwable $e) {
        $this->markTestSkipped('Elasticsearch is not reachable: ' . $e->getMessage());
    }

    FakeVectorProfile::use();

    $service = ElasticsearchService::getInstance();
    $index = VectorMappingStubModel::INDEX;

    try {
        $engine->createIndex(VectorMappingStubModel::class, [], true);

        $mapping = $service->client->indices()->getMapping(['index' => $index])->asArray();
        $embedding = $mapping[$index]['mappings']['properties']['embedding'] ?? null;

        expect($embedding)->not->toBeNull()
            ->and($embedding['type'])->toBe('dense_vector')
            ->and($embedding['dims'])->toBe(384);
    } finally {
        try {
            $service->deleteIndex($index);
        } catch (Throwable) {
            // best-effort cleanup
        }
    }
});

/**
 * ES-gated regression: a paginated vector search must order by similarity, not by
 * id. paginate() previously ran only performKeywordSearch(), so vector/hybrid
 * legs (which EnsembleSearchService drives via paginate) silently degraded to a
 * plain keyword query returning documents in id order.
 */
it('orders a paginated vector search by similarity, not by id', function (): void {
    $engine = (new VectorMappingStubModel)->searchableUsing();

    if (! $engine instanceof ElasticsearchEngine) {
        $this->markTestSkipped('Elasticsearch is not the configured scout engine.');
    }

    try {
        $engine->health();
    } catch (Throwable $e) {
        $this->markTestSkipped('Elasticsearch is not reachable: ' . $e->getMessage());
    }

    FakeVectorProfile::use();

    $service = ElasticsearchService::getInstance();
    $index = VectorMappingStubModel::INDEX;

    $near = array_fill(0, 384, 0.0);
    $near[0] = 1.0; // query points here → doc "2" is the closest
    $far = array_fill(0, 384, 0.0);
    $far[383] = 1.0; // orthogonal → doc "1" is the least similar

    try {
        $engine->createIndex(VectorMappingStubModel::class, [], true);

        // id "1" (lower id) is the LEAST similar; id "2" is the target.
        $service->client->index(['index' => $index, 'id' => '1', 'body' => ['embedding' => $far], 'refresh' => true]);
        $service->client->index(['index' => $index, 'id' => '2', 'body' => ['embedding' => $near], 'refresh' => true]);

        $builder = VectorMappingStubModel::search('*')->where('vector', $near)->take(5);
        $result = $engine->paginate($builder, 5, 1);

        $ids = $result->hits()->map(static fn ($hit): string => (string) $hit->document()->id())->all();

        expect($ids)->not->toBeEmpty()
            ->and($ids[0])->toBe('2'); // similarity order, not id order ("1" first)
    } finally {
        try {
            $service->deleteIndex($index);
        } catch (Throwable) {
            // best-effort cleanup
        }
    }
});

/**
 * ES-gated: createIndex() must push the FULL translated mapping, not only the
 * `embedding` field. Before this fix, ES dynamically mapped everything else,
 * so per-language analyzers (title.it/title.en) and the nested `embeddings`
 * dense_vector sub-field were never applied and the analyzers/kNN field could
 * not exist. It must also strip `meta`/`index` from `nested`/`object` relation
 * fields (`tags`): ES rejects those parameters on those types, so applying the
 * unsanitised mapping fails with a 400 mapper_parsing_exception (the cutover
 * blocker). Skips when Elasticsearch is not the configured engine or is
 * unreachable, so it is a no-op in CI without ES.
 */
it('applies the full field mapping, including locale analyzers and a nested vector, on index creation', function (): void {
    $engine = (new FullMappingStubModel)->searchableUsing();

    if (! $engine instanceof ElasticsearchEngine) {
        $this->markTestSkipped('Elasticsearch is not the configured scout engine.');
    }

    try {
        $engine->health();
    } catch (Throwable $e) {
        $this->markTestSkipped('Elasticsearch is not reachable: ' . $e->getMessage());
    }

    FakeVectorProfile::use();

    $service = ElasticsearchService::getInstance();
    $index = FullMappingStubModel::INDEX;

    try {
        $engine->createIndex(FullMappingStubModel::class, [], true);

        $mapping = $service->client->indices()->getMapping(['index' => $index])->asArray();
        $properties = $mapping[$index]['mappings']['properties'] ?? [];

        $title = $properties['title'] ?? null;
        $embeddings = $properties['embeddings'] ?? null;
        $tags = $properties['tags'] ?? null;

        expect($title)->not->toBeNull()
            ->and($title['properties']['it']['analyzer'])->toBe('italian')
            ->and($title['properties']['en']['analyzer'])->toBe('english')
            ->and($embeddings)->not->toBeNull()
            ->and($embeddings['type'])->toBe('nested')
            ->and($embeddings['properties']['vector']['type'])->toBe('dense_vector')
            // The relation field is applied as a nested type with no meta/index
            // on the container (ES would 400 otherwise); its sub-properties stay.
            ->and($tags)->not->toBeNull()
            ->and($tags['type'])->toBe('nested')
            ->and($tags)->not->toHaveKey('meta')
            ->and($tags['properties']['id']['type'])->toBe('integer')
            ->and($tags['properties']['name']['type'])->toBe('keyword');
    } finally {
        try {
            $service->deleteIndex($index);
        } catch (Throwable) {
            // best-effort cleanup
        }
    }
});

/**
 * ES-gated (Task 13): a kNN vector search must target the nested
 * `embeddings.vector` dense_vector sub-field and still order results by
 * similarity, not by id — the same guarantee as the flat-field test above,
 * proven here specifically for the nested schema produced by Task 1-4.
 */
it('orders a paginated vector search by similarity over the nested embeddings field', function (): void {
    $engine = (new NestedVectorLocaleStubModel)->searchableUsing();

    if (! $engine instanceof ElasticsearchEngine) {
        $this->markTestSkipped('Elasticsearch is not the configured scout engine.');
    }

    try {
        $engine->health();
    } catch (Throwable $e) {
        $this->markTestSkipped('Elasticsearch is not reachable: ' . $e->getMessage());
    }

    FakeVectorProfile::use();

    $service = ElasticsearchService::getInstance();
    $index = NestedVectorLocaleStubModel::INDEX;

    $near = array_fill(0, 384, 0.0);
    $near[0] = 1.0; // query points here → doc "2" is the closest
    $far = array_fill(0, 384, 0.0);
    $far[383] = 1.0; // orthogonal → doc "1" is the least similar

    try {
        $engine->createIndex(NestedVectorLocaleStubModel::class, [], true);

        // Nested `embeddings` field: one entry per translation, agnostic of locale.
        $service->client->index(['index' => $index, 'id' => '1', 'body' => ['embeddings' => [['vector' => $far]], 'locales' => ['it']], 'refresh' => true]);
        $service->client->index(['index' => $index, 'id' => '2', 'body' => ['embeddings' => [['vector' => $near]], 'locales' => ['it']], 'refresh' => true]);

        $builder = NestedVectorLocaleStubModel::search('*')->where('vector', $near)->take(5);
        $result = $engine->paginate($builder, 5, 1);

        $ids = $result->hits()->map(static fn ($hit): string => (string) $hit->document()->id())->all();

        expect($ids)->not->toBeEmpty()
            ->and($ids[0])->toBe('2'); // similarity order, not id order ("1" first)
    } finally {
        try {
            $service->deleteIndex($index);
        } catch (Throwable) {
            // best-effort cleanup
        }
    }
});

/**
 * ES-gated (Task 13): the optional `locales` where clause must become a
 * document-level `terms` filter on the knn query, not a per-vector filter.
 * A document available in the requested locale must be returned even when
 * its nested embeddings vector is a weak match, and a document with a close
 * vector match must still be excluded when it lacks the requested locale —
 * proving the filter operates on the root `locales` field, not inside the
 * nested `embeddings` path.
 */
it('filters a vector search to documents available in the requested locale', function (): void {
    $engine = (new NestedVectorLocaleStubModel)->searchableUsing();

    if (! $engine instanceof ElasticsearchEngine) {
        $this->markTestSkipped('Elasticsearch is not the configured scout engine.');
    }

    try {
        $engine->health();
    } catch (Throwable $e) {
        $this->markTestSkipped('Elasticsearch is not reachable: ' . $e->getMessage());
    }

    FakeVectorProfile::use();

    $service = ElasticsearchService::getInstance();
    $index = NestedVectorLocaleStubModel::INDEX;

    $near = array_fill(0, 384, 0.0);
    $near[0] = 1.0;
    $far = array_fill(0, 384, 0.0);
    $far[383] = 1.0;

    try {
        $engine->createIndex(NestedVectorLocaleStubModel::class, [], true);

        // doc "1": strong vector match, but only available in "en" — must be excluded by an "it" filter.
        $service->client->index(['index' => $index, 'id' => '1', 'body' => ['embeddings' => [['vector' => $near]], 'locales' => ['en']], 'refresh' => true]);
        // doc "2": weak vector match, but available in "it" — must survive the filter despite the weak match.
        $service->client->index(['index' => $index, 'id' => '2', 'body' => ['embeddings' => [['vector' => $far]], 'locales' => ['it', 'en']], 'refresh' => true]);
        // doc "3": strong vector match and available in "it" — must be returned.
        $service->client->index(['index' => $index, 'id' => '3', 'body' => ['embeddings' => [['vector' => $near]], 'locales' => ['it']], 'refresh' => true]);

        $builder = NestedVectorLocaleStubModel::search('*')->where('vector', $near)->where('locales', ['it'])->take(5);
        $result = $engine->paginate($builder, 5, 1);

        $ids = $result->hits()->map(static fn ($hit): string => (string) $hit->document()->id())->all();

        expect($ids)->toEqualCanonicalizing(['2', '3'])
            ->and($ids)->not->toContain('1');
    } finally {
        try {
            $service->deleteIndex($index);
        } catch (Throwable) {
            // best-effort cleanup
        }
    }
});
