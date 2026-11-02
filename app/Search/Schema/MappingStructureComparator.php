<?php

declare(strict_types=1);

namespace Modules\Core\Search\Schema;

use BackedEnum;

/**
 * Compares a model's declared Elasticsearch mapping against the live mapping,
 * field by field, so index drift (for example a field whose type changed from
 * `text` to `object` after a schema change) is detected instead of silently
 * causing `document_parsing_exception` at index time.
 *
 * Only declared fields are checked, and only by mapping type. Extra live fields
 * added by dynamic mapping, and ES-normalised sub-keys (keyword sub-fields the
 * model does not declare, analyzers, `doc_values`), are ignored, so a
 * structurally correct index never reports drift.
 *
 * A sub-field the model declares (`fields`, such as the `exact` sub-field of a
 * stemmed text field) is checked too. A missing one is reported as needing a
 * rebuild: the mapping can be patched to add it, but only documents written
 * after that are analysed into it.
 */
final class MappingStructureComparator
{
    /**
     * @param  array<array-key, mixed>  $expected  Declared `properties` map.
     * @param  array<array-key, mixed>  $live  Live Elasticsearch `properties` map.
     */
    public static function matches(array $expected, array $live): bool
    {
        return self::diff($expected, $live) === [];
    }

    /**
     * List, in human-readable form, every declared field whose live mapping is
     * missing or has a different type. Field paths are dotted, so a drifted
     * per-locale sub-field reads as `title.en`.
     *
     * @param  array<array-key, mixed>  $expected  Declared `properties` map.
     * @param  array<array-key, mixed>  $live  Live Elasticsearch `properties` map.
     * @return list<string>
     */
    public static function diff(array $expected, array $live, string $prefix = ''): array
    {
        $mismatches = [];

        foreach ($expected as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $path = $prefix === '' ? (string) $name : $prefix . '.' . $name;
            $live_definition = $live[$name] ?? null;

            if (! is_array($live_definition)) {
                $mismatches[] = sprintf("field '%s' is declared by the model but missing from the live index", $path);

                continue;
            }

            $expected_type = self::type($definition);
            $live_type = self::type($live_definition);

            if ($expected_type !== $live_type) {
                $mismatches[] = sprintf(
                    "field '%s' is '%s' in the live index but the model declares '%s'",
                    $path,
                    $live_type ?? 'unknown',
                    $expected_type ?? 'unknown',
                );

                continue;
            }

            if (is_array($definition['fields'] ?? null)) {
                $mismatches = array_merge(
                    $mismatches,
                    self::subFieldDiff($definition['fields'], is_array($live_definition['fields'] ?? null) ? $live_definition['fields'] : [], $path),
                );
            }

            if ($expected_type === 'object'
                && is_array($definition['properties'] ?? null)
                && is_array($live_definition['properties'] ?? null)) {
                $mismatches = array_merge(
                    $mismatches,
                    self::diff($definition['properties'], $live_definition['properties'], $path),
                );
            }
        }

        return $mismatches;
    }

    /**
     * The declared sub-fields (`fields`) of one field that the live index lacks or maps with another type.
     *
     * @param  array<array-key, mixed>  $expected  Declared `fields` map.
     * @param  array<array-key, mixed>  $live  Live `fields` map.
     * @return list<string>
     */
    private static function subFieldDiff(array $expected, array $live, string $path): array
    {
        $mismatches = [];

        foreach ($expected as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $sub_path = $path . '.' . $name;
            $live_definition = $live[$name] ?? null;

            if (! is_array($live_definition)) {
                $mismatches[] = sprintf(
                    "sub-field '%s' is declared by the model but missing from the live index; rebuild the index to analyse the documents into it",
                    $sub_path,
                );

                continue;
            }

            $expected_type = self::type($definition);
            $live_type = self::type($live_definition);

            if ($expected_type !== $live_type) {
                $mismatches[] = sprintf(
                    "field '%s' is '%s' in the live index but the model declares '%s'",
                    $sub_path,
                    $live_type ?? 'unknown',
                    $expected_type ?? 'unknown',
                );
            }
        }

        return $mismatches;
    }

    /**
     * Resolve a field's mapping type. Elasticsearch omits `type` for object
     * fields and reports only their `properties`, so an entry carrying
     * `properties` and no `type` is treated as an object.
     *
     * @param  array<array-key, mixed>  $definition
     */
    private static function type(array $definition): ?string
    {
        $type = $definition['type'] ?? null;

        if ($type instanceof BackedEnum) {
            $type = $type->value;
        }

        if (is_string($type)) {
            return $type;
        }

        return isset($definition['properties']) ? 'object' : null;
    }
}
