<?php

declare(strict_types=1);

namespace Modules\Core\Search\Translators;

use Modules\Core\Search\Contracts\ISchemaTranslator;
use Modules\Core\Search\Schema\FieldDefinition;
use Modules\Core\Search\Schema\FieldType;
use Modules\Core\Search\Schema\IndexType;
use Modules\Core\Search\Schema\SchemaDefinition;
use Modules\Core\Search\Support\VectorProfileContext;

class ElasticsearchTranslator implements ISchemaTranslator
{
    public function translate(SchemaDefinition $schema): array
    {
        $mapping = [
            'mappings' => [
                'properties' => [],
            ],
        ];

        foreach ($schema->getFields() as $field) {
            $mapping['mappings']['properties'][$field->name] = $this->translateField($field);
        }

        return $mapping;
    }

    public function getEngineName(): string
    {
        return 'elasticsearch';
    }

    private function translateField(FieldDefinition $field): array
    {
        // Handle Object field with locale_properties (per-language text with analyzers)
        if ($field->type === FieldType::Object && is_array($field->options['locale_properties'] ?? null)) {
            $properties = [];

            foreach ($field->options['locale_properties'] as $locale => $definition) {
                $properties[(string) $locale] = [
                    'type' => 'text',
                    'analyzer' => (string) ($definition['analyzer'] ?? 'standard'),
                ];
            }

            return ['type' => 'object', 'properties' => $properties];
        }

        // Handle Array field with vector option (nested with dense_vector): the option wins, the vector profile fills the gaps
        if ($field->type === FieldType::Array && is_array($field->options['vector'] ?? null)) {
            $vector = $field->options['vector'];
            $profile = isset($vector['dimensions'], $vector['similarity']) ? null : VectorProfileContext::get();
            $dimensions = $vector['dimensions'] ?? $profile?->dimensions;
            $similarity = $vector['similarity'] ?? $profile?->similarity;

            if ($dimensions !== null && $similarity !== null) {
                return [
                    'type' => 'nested',
                    'properties' => [
                        'vector' => [
                            'type' => 'dense_vector',
                            'dims' => (int) $dimensions,
                            'index' => true,
                            'similarity' => (string) $similarity,
                        ],
                    ],
                ];
            }
        }

        $esField = match ($field->type) {
            FieldType::Text => [
                'type' => 'text',
                'analyzer' => $field->options['analyzer'] ?? 'standard',
            ],
            FieldType::Keyword => [
                'type' => 'keyword',
            ],
            FieldType::Integer => [
                'type' => 'integer',
            ],
            FieldType::Float => [
                'type' => 'float',
            ],
            FieldType::Boolean => [
                'type' => 'boolean',
            ],
            FieldType::Date => [
                // dates and randge operations
                'type' => 'date',
                'format' => $field->options['format'] ?? 'strict_date_optional_time||epoch_millis',
                // equals operations and sorts
                'fields' => [
                    'keyword' => [
                        'type' => FieldType::Keyword,
                    ],
                ],
            ],
            FieldType::Vector => [
                'type' => 'dense_vector',
                'dims' => $field->options['dimensions'] ?? 1536,
                'index' => true,
                'similarity' => $field->options['similarity'] ?? 'cosine',
            ],
            FieldType::Array => [
                'type' => $field->options['element_type'] ?? 'text',
            ],
            FieldType::Object => [
                'type' => 'object',
                'properties' => $field->options['properties'] ?? [],
            ],
            FieldType::Geocode => [
                'type' => 'geo_point',
            ],
        };

        if ($field->type === FieldType::Array && isset($field->options['properties'])) {
            $esField['type'] = 'nested';
            $esField['properties'] = $this->translateNestedProperties($field);
        }

        if (is_string($field->options['relation'] ?? null)) {
            $esField['meta']['relation'] = $field->options['relation'];
        }

        // Add index-specific options
        if ($field->hasIndexType(IndexType::Sortable)) {
            $esField['doc_values'] = true;
        }

        if ($field->hasIndexType(IndexType::Filterable)) {
            $esField['index'] = true;
            $esField['meta']['filterable'] = true;
        }

        return $esField;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function translateNestedProperties(FieldDefinition $field): array
    {
        $properties = [];

        foreach (($field->options['properties'] ?? []) as $name => $definition) {
            if (! is_string($name)) {
                continue;
            }

            [$type, $filterable] = $this->nestedPropertyDefinition($definition);
            $property = $this->translateField(new FieldDefinition($name, $type, $filterable ? [IndexType::Filterable] : []));

            if ($filterable) {
                $property['meta']['filterable'] = true;
            }

            $properties[$name] = $property;
        }

        return $properties;
    }

    /**
     * @return array{0: FieldType, 1: bool}
     */
    private function nestedPropertyDefinition(mixed $definition): array
    {
        if ($definition instanceof FieldType) {
            return [$definition, false];
        }

        if (is_array($definition)) {
            $type = $definition['type'] ?? FieldType::Text;

            return [
                $type instanceof FieldType ? $type : FieldType::fromValue($type),
                ($definition['filterable'] ?? false) === true || ($definition['facet'] ?? false) === true,
            ];
        }

        return [FieldType::fromValue($definition), false];
    }
}
