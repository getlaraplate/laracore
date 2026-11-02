<?php

declare(strict_types=1);

namespace Modules\Core\Search\Engines;

use Closure;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Search\Translators\ElasticsearchTranslator;
use Modules\Core\Services\ElasticsearchService;
use Throwable;

/**
 * The fields a text query searches when the results are requested in a language.
 *
 * Vectors carry no language, but text does: `title.it` is analysed as Italian and `title.en` as English, so a
 * request in Italian searches the Italian field of every per-language object and never the English one. Fields
 * with no language (keyword and text values such as `entity` or `preset`) stay. The mapping read is the live
 * one, because the component fields (`subtitle`, `content`, ...) are mapped dynamically, as an object with a
 * sub-field per language; the model's declared mapping is the fallback when the cluster cannot be asked.
 *
 * A text field with an exact sub-field (analysed without stemming, see {@see ElasticsearchTranslator}) is
 * searched through both, the exact one right after the stemmed one and with the same boost.
 */
final readonly class ElasticsearchLocaleTextFields
{
    private const int CACHE_SECONDS = 300;

    private const string TITLE_BOOST = '^2';

    /**
     * @param  (Closure(string): (array<string, mixed>|null))|null  $liveProperties  Replaces the cluster, for tests.
     */
    public function __construct(private ?Closure $liveProperties = null) {}

    /**
     * Nested fields are left out (a plain multi_match cannot reach them), and so are the dates, numbers,
     * booleans and vectors, which are not text, and `locales`, which would make the word "it" match every
     * document available in Italian.
     *
     * @param  array<array-key, mixed>  $properties  The `properties` of an Elasticsearch mapping.
     * @param  list<string>  $locales  The languages the results are requested in.
     * @param  list<string>  $available  Every language the application has, to tell an object per language from any other.
     * @return list<string>
     */
    public static function fromProperties(array $properties, array $locales, array $available): array
    {
        $fields = [];
        self::collect($properties, '', $locales, $available, $fields);

        return array_values(array_unique($fields));
    }

    /**
     * @param  list<string>  $locales
     * @return list<string> The fields of those languages, or none when no mapping is known.
     */
    public function forModel(Model $model, string $index, array $locales): array
    {
        $properties = $this->live($index) ?? $this->declared($model);

        if ($properties === []) {
            return [];
        }

        return self::fromProperties($properties, $locales, array_values(array_filter(LocaleContext::getAvailable(), is_string(...))));
    }

    /**
     * The mapping is decoded JSON: a key is a field name only when it is a string.
     *
     * @param  array<array-key, mixed>  $properties
     * @param  list<string>  $locales
     * @param  list<string>  $available
     * @param  list<string>  $fields
     */
    private static function collect(array $properties, string $prefix, array $locales, array $available, array &$fields): void
    {
        foreach ($properties as $name => $definition) {
            if (! is_string($name) || ! is_array($definition)) {
                continue;
            }

            if ($prefix === '' && $name === 'locales') {
                continue;
            }

            $path = $prefix . $name;
            $type = $definition['type'] ?? 'object';
            $children = $definition['properties'] ?? null;

            if (is_array($children)) {
                if ($type === 'nested') {
                    continue;
                }

                if (array_intersect(array_keys($children), $available) !== []) {
                    foreach ($locales as $locale) {
                        $sub = $children[$locale] ?? null;

                        if (is_array($sub) && in_array($sub['type'] ?? null, ['text', 'keyword'], true)) {
                            $boost = $path === 'title' ? self::TITLE_BOOST : '';
                            $fields[] = $path . '.' . $locale . $boost;

                            if (self::hasExactSubField($sub)) {
                                $fields[] = $path . '.' . $locale . '.' . ElasticsearchTranslator::EXACT_SUB_FIELD . $boost;
                            }
                        }
                    }

                    continue;
                }

                self::collect($children, $path . '.', $locales, $available, $fields);

                continue;
            }

            if (in_array($type, ['text', 'keyword'], true)) {
                $fields[] = $path;

                if (self::hasExactSubField($definition)) {
                    $fields[] = $path . '.' . ElasticsearchTranslator::EXACT_SUB_FIELD;
                }
            }
        }
    }

    /**
     * Whether a text field carries the sub-field analysed without stemming. An index built before it existed
     * has none, and its fields are searched as they were.
     *
     * @param  array<array-key, mixed>  $definition
     */
    private static function hasExactSubField(array $definition): bool
    {
        $exact = is_array($definition['fields'] ?? null) ? ($definition['fields'][ElasticsearchTranslator::EXACT_SUB_FIELD] ?? null) : null;

        return is_array($exact) && ($exact['type'] ?? null) === 'text';
    }

    /**
     * The `mappings.properties` of an index definition, or null when it has none.
     *
     * @return array<array-key, mixed>|null
     */
    private static function mappingProperties(mixed $definition): ?array
    {
        $mappings = is_array($definition) ? ($definition['mappings'] ?? null) : null;
        $properties = is_array($mappings) ? ($mappings['properties'] ?? null) : null;

        return is_array($properties) ? $properties : null;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function live(string $index): ?array
    {
        if ($this->liveProperties instanceof Closure) {
            return ($this->liveProperties)($index);
        }

        try {
            $properties = Cache::remember('search:es-mapping:' . $index, self::CACHE_SECONDS, static function () use ($index): ?array {
                $response = ElasticsearchService::getInstance()->client->indices()->getMapping(['index' => $index]);

                // An asynchronous client answers a promise: there is no mapping to read now.
                return $response instanceof Elasticsearch ? self::mappingProperties(array_values($response->asArray())[0] ?? null) : null;
            });

            return is_array($properties) ? $properties : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function declared(Model $model): array
    {
        if (! method_exists($model, 'getSearchMapping')) {
            return [];
        }

        return self::mappingProperties($model->getSearchMapping()) ?? [];
    }
}
