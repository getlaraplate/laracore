<?php

declare(strict_types=1);

namespace Modules\Core\Search\Traits;

use Elastic\ScoutDriver\Engine as ElasticEngine;
use Elastic\ScoutDriverPlus\Searchable as ElasticScoutSearchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Engines\DatabaseEngine;
use Laravel\Scout\Engines\TypesenseEngine;
use Modules\Core\Contracts\IEmbeddableModel;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Events\ModelsRequireIndexing;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Models\Concerns\HasValidity;
use Modules\Core\Models\Media;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Overrides\LocaleScope;
use Modules\Core\Search\AdaptiveBatchController;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\DeferredSearchIndexing;
use Modules\Core\Search\Exceptions\UnsupportedSearchEngineException;
use Modules\Core\Search\Schema\FieldDefinition;
use Modules\Core\Search\Schema\FieldType;
use Modules\Core\Search\Schema\IndexType;
use Modules\Core\Search\Schema\SchemaDefinition;
use Modules\Core\Search\Schema\SchemaManager;
use Modules\Core\Search\SearchableContributorRegistry;
use Modules\Core\Search\Support\VectorProfileContext;
use Modules\Core\SoftDeletes\SoftDeletes;
use Modules\Core\Support\SearchEngineAvailability;
use Spatie\MediaLibrary\HasMedia;
use Throwable;

/**
 * Extended searchable trait that supports multiple engines
 * Provides enhanced functionality for Elasticsearch and Typesense.
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements IEmbeddableModel
 */
trait Searchable
{
    use ElasticScoutSearchable {
        queueMakeSearchable as private baseQueueMakeSearchable;
        syncMakeSearchable as private baseSyncMakeSearchableSync;
        searchableConnection as private baseSearchableConnection;
    }
    use RemovesEmbeddingsWithRecord;

    /**
     * Field name for indexing timestamp.
     */
    public static string $indexedAtField = '_indexed_at';

    private ?string $cacheConnection = null;

    /**
     * Reindex all records of this model.
     */
    public static function reindex(?int $chunk = null): void
    {
        static::makeAllSearchable($chunk);
    }

    public function setCacheConnection(string $connection): void
    {
        $this->cacheConnection = $connection;
    }

    public function searchableConnection(): ?string
    {
        return $this->cacheConnection;
    }

    /**
     * @param  iterable<int, static>|static  $models
     */
    public function queueMakeSearchable($models): void
    {
        // A bulk run (an import) records the models and indexes them in bulk later.
        if (app(DeferredSearchIndexing::class)->defer(is_iterable($models) ? $models : [$models])) {
            return;
        }

        $this->degradeWhenSearchEngineUnreachable('ensure indexes', function () use ($models): void {
            $this->ensureIndexesForModels($models);
        });

        $collection = self::modelsCollection($models);
        $sync = ! config('scout.queue');

        // Bulk import (many models at once): pre-process the whole chunk in one
        // batched pass and write the engine in adaptive batches, instead of the
        // per-model event fan-out kept for a real-time single save.
        if (! $sync && $collection->count() > 1) {
            $this->bulkQueueMakeSearchable($collection);

            return;
        }

        foreach ($collection as $model) {
            // Emit event instead of calling job directly
            // Listeners will handle pre-processing (embeddings, translations, etc.)
            // and finalize listener will dispatch IndexInSearchJob when all are completed
            //
            // Tolerated per model, because in sync mode a listener runs IndexInSearchJob
            // here and that job rethrows so a queue worker can retry it. Without this
            // the rethrow leaves the two guarded blocks below untouched and takes the
            // domain write down with it, which is the opposite of what they promise.
            $this->degradeWhenSearchEngineUnreachable(
                'dispatch indexing',
                function () use ($model, $sync): void {
                    $event = new ModelRequiresIndexing($model, $sync);
                    event($event);

                    // Save event in cache for the finalize listener
                    if (! $sync) {
                        Cache::put(ModelRequiresIndexing::cacheKey($model), $event, now()->addMinutes(ModelRequiresIndexing::CACHE_TTL_MINUTES));
                    }
                },
            );
        }

        // If sync mode, the finalize listener will handle everything synchronously
        // Otherwise, events will be handled by listeners asynchronously
        if ($sync) {
            // In sync mode, we still need to call base method for immediate indexing
            // if no pre-processing is required
            $this->degradeWhenSearchEngineUnreachable(
                'index models',
                fn (): mixed => $this->baseQueueMakeSearchable($collection),
            );
        }
    }

    /**
     * @param  iterable<int, static>|static  $models
     */
    public function syncMakeSearchable($models): void
    {
        $this->degradeWhenSearchEngineUnreachable('ensure indexes', function () use ($models): void {
            $this->ensureIndexesForModels($models);
        });

        $models = self::modelsCollection($models);

        foreach ($models as $model) {
            // Emit event for sync mode
            //
            // Guarded for the same reason as in queueMakeSearchable(): the listener
            // runs IndexInSearchJob inline, and that job rethrows.
            $this->degradeWhenSearchEngineUnreachable(
                'dispatch indexing',
                static function () use ($model): void {
                    event(new ModelRequiresIndexing($model, true));
                },
            );

            // In sync mode, listeners will handle everything synchronously
            // The finalize listener will dispatch IndexInSearchJob immediately
        }

        $this->degradeWhenSearchEngineUnreachable(
            'index models',
            fn (): mixed => $this->baseSyncMakeSearchableSync($models),
        );
    }

    /**
     * Scout bulk-import hook: strips `LocaleScope` so every translated row is
     * indexed, including mono-language (non-default-locale) content that the
     * runtime global scope would otherwise hide from `Content::query()`.
     * `withoutGlobalScope` is a no-op for models that never register that
     * scope (e.g. Ticket, Location), so this is safe trait-wide.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->withoutGlobalScope(LocaleScope::class);
    }

    /**
     * Scout hook, run before a chunk is written to the engine: eager-load the
     * relations toSearchableArray() reads, declared by the model's
     * toSearchableWith() when it has one, so serializing the chunk does not fire
     * those relations one query per model. loadMissing keeps whatever an earlier
     * pass already loaded. A model without toSearchableWith(), or a plain
     * (non-Eloquent) collection, is returned untouched.
     *
     * @param  Collection<int, covariant Model>  $models
     * @return Collection<int, covariant Model>
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        if (! $models instanceof EloquentCollection || ! method_exists($this, 'toSearchableWith')) {
            return $models;
        }

        $with = $this->toSearchableWith();

        return $with === [] ? $models : $models->loadMissing($with);
    }

    /**
     * Index the given models of this class through the bulk path now: one
     * batched pre-process pass (embeddings) and adaptive engine writes,
     * whatever `scout.queue` says. {@see DeferredSearchIndexing} flushes here.
     *
     * @param  Collection<int, static>  $models
     */
    public function makeSearchableInBulk(Collection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $this->degradeWhenSearchEngineUnreachable('ensure indexes', function () use ($models): void {
            $this->ensureIndexesForModels($models);
        });

        $this->bulkQueueMakeSearchable($models->values());
    }

    /**
     * Extends the standard method with support for embeddings and other data.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $engine = $this->searchableUsing();
        $key = $this->getKey();

        $array = [
            'id' => is_scalar($key) ? (string) $key : '',
            'connection' => $this->getConnectionName() ?: 'default',
            'entity' => $this->getTable(),
            self::$indexedAtField => now()->utc()->toIso8601ZuluString(),
        ];

        if (class_uses_trait($this, HasValidity::class)) {
            $array['valid_from'] = $this->{HasValidity::validFromKey()};
            $array['valid_to'] = $this->{HasValidity::validToKey()};
        }

        if (class_uses_trait($this, SoftDeletes::class) || $this->softDeletesEnabled ?? true) {
            $array['is_deleted'] = $this->{self::getIsDeletedColumn()};
        }

        // Add embeddings if available (agnostic array: no locale in ES, one entry per ModelEmbedding row)
        if ($this->vectorSearchEnabled() && $engine instanceof ISearchEngine && $engine->supportsVectorSearch()) {
            // Reuse the eager-loaded relation on the bulk path (adaptiveBulkIndex
            // pre-loads it) and query only when it is not loaded, so serializing a
            // chunk does not fire one embeddings query per model.
            $model_key = VectorProfileContext::modelKey();
            $loaded = $this->relationLoaded('embeddings') ? $this->getRelation('embeddings') : null;
            $embeddings = $loaded instanceof Collection
                ? $loaded
                : ($model_key !== null ? $this->embeddings()->where('model_key', $model_key)->get() : $this->embeddings()->get());
            $vectors = $embeddings
                ->whereInstanceOf(ModelEmbedding::class)
                ->when($model_key !== null, static fn (Collection $rows): Collection => $rows->filter(static fn (ModelEmbedding $e): bool => $model_key === $e->model_key))
                ->map(static fn (ModelEmbedding $e): array => ['vector' => $e->embedding])
                ->values()->all();

            if ($vectors !== []) {
                $array['embeddings'] = $vectors;
            }
        }

        // Generic contributor seam (M4a): registered modules add their own fields
        // for this model without Core knowing them. Base keys win; empty registry
        // is a no-op.
        $array += app(SearchableContributorRegistry::class)->fieldsFor($this);

        // Parent enrichment (media M9): an owner carries the compact surrogate of its
        // media, so it is findable by what only its media show.
        if ($this instanceof HasMedia && ! $this instanceof Media) {
            $surrogate = $this->mediaSurrogateText();

            if ($surrogate !== '') {
                $array['media_surrogate'] = $surrogate;
            }
        }

        return $array;
    }

    /**
     * Prepare text data for embedding generation.
     * If the model has translations, concatenate all translations for multilingual embedding.
     */
    public function prepareDataToEmbed(): ?string
    {
        if (! isset($this->embed) || $this->embed === []) {
            return null;
        }

        return mb_trim(implode(' ', $this->prepareDataToEmbedByLocale()));
    }

    /**
     * Prepare text data for embedding generation, keyed by locale.
     * Translated models: one entry per locale that has a translation with
     * non-empty embeddable text (or just $locale, when given). Non-translated
     * models: a single entry keyed by the app default locale.
     *
     * @return array<string, string>
     */
    public function prepareDataToEmbedByLocale(?string $locale = null): array
    {
        if (! isset($this->embed) || $this->embed === []) {
            return [];
        }

        if (! class_uses_trait($this, HasTranslations::class)) {
            $text = $this->collectEmbedText(fn (string $attr) => $this->{$attr});

            return $text === '' ? [] : [LocaleContext::getDefault() => $text];
        }

        $result = [];
        $locales = $locale !== null ? [$locale] : LocaleContext::getAvailable();

        // Reuse the eager-loaded translations relation (bulk path) so this does not
        // fire one query per locale per model; getTranslation() always queries, so
        // fall back to it only when the relation is not loaded (per-model path).
        $loaded = $this->relationLoaded('translations') ? $this->getRelation('translations') : null;
        $loaded_translations = $loaded instanceof Collection ? $loaded->keyBy('locale') : null;

        foreach ($locales as $loc) {
            // with_fallback: false — a locale with no translation row of its own must be
            // skipped, not silently resolved to the default-locale translation. Fallback
            // is on by default (Content), so getTranslation($loc) alone would return the
            // same default-locale row for every available locale, mislabeling embeddings.
            // The loaded relation, keyed by locale, gives the own-locale row (no fallback).
            $translation = match (true) {
                $loaded_translations !== null => $loaded_translations[$loc] ?? null,
                method_exists($this, 'getTranslation') => $this->getTranslation($loc, with_fallback: false),
                default => null,
            };

            if (! $translation) {
                continue;
            }

            $text = $this->collectEmbedText(fn (string $attr) => $translation->{$attr} ?? null);

            if ($text !== '') {
                $result[$loc] = $text;
            }
        }

        return $result;
    }

    /**
     * Relationship with model embeddings.
     * ModelEmbedding is in Core (structure), AI module handles generation/population.
     *
     * @return MorphMany<ModelEmbedding, $this>
     */
    public function embeddings(): MorphMany
    {
        return $this->morphMany(ModelEmbedding::class, 'model');
    }

    /**
     * Get field mapping for search engine
     * Convert generic field definitions to the format required by the current search engine.
     *
     * @return array<string, mixed>
     */
    public function getSearchMapping(?SchemaDefinition $schema = null): array
    {
        if (! $schema instanceof SchemaDefinition) {
            $schema = $this->getSchemaDefinition();
            $document = $this->toSearchableArray();

            foreach ($document as $key => $value) {
                if ($key === 'embedding') {
                    $schema->addField(new FieldDefinition($key, FieldType::Vector, [IndexType::Searchable, IndexType::Vector], ['dimensions' => self::configInt('core.search.vector.dimensions', 384)]));
                } else {
                    $schema->addField(new FieldDefinition($key, FieldType::fromValue($value), [IndexType::Searchable]));
                }
            }
        }

        // Get the current engine and translate
        $engine = $this->searchableUsing();

        if ($engine instanceof ElasticEngine) {
            $engineName = 'elasticsearch';
        } elseif ($engine instanceof TypesenseEngine) {
            $engineName = 'typesense';
        } elseif ($engine instanceof DatabaseEngine) {
            $engineName = 'database';
        } else {
            throw new UnsupportedSearchEngineException('Unsupported engine ' . $engine::class);
        }

        $schemaManager = resolve(SchemaManager::class);

        return $schemaManager->translateForEngine($schema, $engineName);
    }

    /**
     * Check if the index exists and create if needed.
     */
    public function ensureIndexExists(): bool
    {
        $engine = $this->searchableUsing();

        if ($engine instanceof ISearchEngine) {
            return $engine->ensureIndex($this);
        }

        if (method_exists($engine, 'createIndex')) {
            $needs_creation = true;

            if (is_callable([$engine, 'indexExists'])) {
                $needs_creation = ! (bool) call_user_func([$engine, 'indexExists'], $this->searchableAs());
            } elseif (is_callable([$engine, 'checkIndex'])) {
                $needs_creation = ! (bool) call_user_func([$engine, 'checkIndex'], $this);
            }

            if ($needs_creation) {
                // Scout's native engines create an index by name.
                $engine->createIndex($this->searchableAs());

                return true;
            }
        }

        return false;
    }

    /**
     * Create or update the index.
     */
    public function createIndex(): void
    {
        $engine = $this->searchableUsing();

        if ($engine instanceof ISearchEngine) {
            $engine->createIndex($this);
        } elseif (method_exists($engine, 'createIndex')) {
            // Use Scout's native method.
            $engine->createIndex($this->searchableAs());
        }
    }

    /**
     * Get the timestamp of last indexing.
     */
    public function getLastIndexedTimestamp(): ?string
    {
        $engine = $this->searchableUsing();

        if ($engine instanceof ISearchEngine) {
            return $engine->getLastIndexedTimestamp($this);
        }

        return null;
    }

    /**
     * Public capability query for embedding-driven indexing: the model declares
     * embeddable attributes AND vector search is enabled. Encapsulates the
     * protected `$embed` and the private vector-enabled check so external
     * listeners (AI embeddings) can ask without reaching into model internals.
     */
    public function isEmbeddable(): bool
    {
        return $this->vectorSearchEnabled()
            && isset($this->embed)
            && $this->embed !== [];
    }

    /**
     * Public accessor for the embeddable field list ($embed), so external
     * consumers (e.g. an observer deciding whether a translation change requires
     * re-embedding) don't need to reach into the protected property directly.
     *
     * @return list<string>
     */
    public function getEmbedFields(): array
    {
        return $this->embed ?? [];
    }

    /**
     * The models Scout hands over (one model or several) as a list.
     *
     * @param  iterable<int, static>|static  $models
     * @return Collection<int, static>
     */
    private static function modelsCollection($models): Collection
    {
        $list = [];

        foreach (is_iterable($models) ? $models : [$models] as $model) {
            $list[] = $model;
        }

        return collect($list);
    }

    /**
     * A number read from the configuration, which an env variable may give as a numeric string.
     */
    private static function configInt(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Bulk path: pre-process every model in the chunk together (a listener may
     * embed them in one batched call), then write the engine in adaptive
     * batches. The per-model event fan-out is skipped here on purpose.
     *
     * @param  Collection<int, static>  $models
     */
    private function bulkQueueMakeSearchable(Collection $models): void
    {
        // Eager-load the relations both passes read per model (embeddings for the
        // freshness check and the vector serialization, translations for the
        // embeddable text), so the chunk costs a couple of queries instead of a
        // handful per model. The pre-process listener and adaptiveBulkIndex share
        // these same instances, so the loads are reused across both.
        $this->eagerLoadForIndexing($models);

        $this->degradeWhenSearchEngineUnreachable('bulk pre-process', static function () use ($models): void {
            event(new ModelsRequireIndexing($models, true));
        });

        $this->degradeWhenSearchEngineUnreachable('bulk index', function () use ($models): void {
            $this->adaptiveBulkIndex($models);
        });
    }

    /**
     * Eager-load, in one query each, the relations the bulk indexing passes read
     * per model. Homogeneous chunk: the first model decides which relations exist.
     * `loadMissing` leaves already-loaded relations untouched.
     *
     * @param  Collection<int, static>  $models
     */
    private function eagerLoadForIndexing(Collection $models): void
    {
        $sample = $models->first();

        if ($sample === null) {
            return;
        }

        // Wrap in an Eloquent collection (the chunk may be a base collection) to
        // reach load/loadMissing; both load onto the shared model instances, so
        // $models and every later chunk see the relations too.
        $chunk = new EloquentCollection($models->all());

        $relations = ['embeddings'];

        if (class_uses_trait($sample, HasTranslations::class)) {
            $relations[] = 'translations';
        }

        $chunk->loadMissing($relations);

        // The model's own Scout hook for preloading the relations its
        // toSearchableArray() reads (contributors, taxonomies, ...). Our direct
        // engine write bypasses Scout, which calls this before update(), so invoke
        // it here for that eager-load side effect on the shared instances.
        $sample->makeSearchableUsing($chunk);
    }

    /**
     * Write the collection to the engine in adaptive batches: the batch size
     * grows on fast writes and shrinks on a slow or failing one, so it stays
     * driver-agnostic (each engine's own bulk handles the chunk) and behaves on
     * any server without tuning.
     *
     * @param  Collection<int, static>  $models
     */
    private function adaptiveBulkIndex(Collection $models): void
    {
        $engine = $this->searchableUsing();
        $max = max(1, self::configInt('core.bulk_index_batch', 100));

        $controller = new AdaptiveBatchController(minBatch: 1, maxBatch: $max, startBatch: $max);

        $controller->run(
            array_values($models->all()),
            function (array $chunk) use ($engine): array {
                $engine->update($this->newCollection($chunk));

                return [];
            },
        );
    }

    /**
     * Run a search indexing step, tolerating an unreachable engine.
     *
     * Indexing is a side effect of a domain write: when the engine cannot be
     * reached the write must still succeed, with the failure logged so the
     * documents can be reindexed later. Any other failure (schema, payload,
     * authentication) still propagates, and so does this one inside a strict
     * scope, which is what a queued search job runs in
     * ({@see \Modules\Core\Search\Jobs\Middleware\FailWhenSearchEngineUnreachable}).
     *
     * @param  callable(): mixed  $operation
     */
    private function degradeWhenSearchEngineUnreachable(string $description, callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            if (SearchEngineAvailability::isStrict() || ! SearchEngineAvailability::isUnreachable($exception)) {
                throw $exception;
            }

            Log::warning(sprintf('Search engine unreachable, skipped %s for [%s]', $description, static::class), [
                'driver' => config('scout.driver'),
                'index' => $this->searchableAs(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  iterable<int, static>|static  $models
     */
    private function ensureIndexesForModels($models): void
    {
        if (! is_iterable($models)) {
            $this->ensureIndexExists();

            return;
        }

        $by_class = [];

        foreach ($models as $model) {
            $class = $model::class;

            if (isset($by_class[$class])) {
                continue;
            }

            $model->ensureIndexExists();

            $by_class[$class] = $model;
        }
    }

    private function vectorSearchEnabled(): bool
    {
        /** @phpstan-ignore-next-line false-positive: config loaded via module */
        if (! Config::has('core.search.vector.enabled')) {
            return false;
        }

        /** @phpstan-ignore-next-line false-positive: config loaded via module */
        return (bool) Config::get('core.search.vector.enabled');
    }

    private function mediaSurrogateText(): string
    {
        if (! $this instanceof HasMedia) {
            return '';
        }

        $loaded = $this->relationLoaded('media') ? $this->getRelation('media') : null;
        $media = $loaded instanceof Collection ? $loaded : $this->media()->get();

        return mb_trim(implode(' ', $media
            ->whereInstanceOf(Media::class)
            ->map(static fn (Media $item): string => $item->ownerSurrogateText())
            ->filter(static fn (string $text): bool => $text !== '')
            ->all()));
    }

    private function getSchemaDefinition(): SchemaDefinition
    {
        $schema = new SchemaDefinition($this->getTable());

        // Generic contributor seam (M4a): add registered contributors' mapping
        // fields to the model's index schema. Empty registry is a no-op.
        foreach (app(SearchableContributorRegistry::class)->mappingFor(static::class) as $field) {
            $schema->addField($field);
        }

        if (is_subclass_of(static::class, HasMedia::class) && ! is_a(static::class, Media::class, true)) {
            $schema->addField(new FieldDefinition('media_surrogate', FieldType::Text, [IndexType::Searchable]));
        }

        return $schema;
    }

    /**
     * Check if a value is valid for embedding.
     *
     * @phpstan-assert-if-true non-empty-string $value
     */
    private function isValidEmbedValue(mixed $value): bool
    {
        return $value
            && is_string($value)
            && $value !== ''
            && $value !== '0';
    }

    /**
     * Concatenate embeddable attribute values retrieved via $get into a single
     * trimmed string, skipping invalid values. Shared by prepareDataToEmbed
     * and prepareDataToEmbedByLocale.
     *
     * @param  callable(string): mixed  $get
     */
    private function collectEmbedText(callable $get): string
    {
        $data = '';

        foreach ($this->embed as $attribute) {
            $value = $get($attribute);

            if ($this->isValidEmbedValue($value)) {
                $data .= ' ' . $value;
            }
        }

        return mb_trim($data);
    }
}
