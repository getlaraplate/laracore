<?php

declare(strict_types=1);

namespace Modules\Core\Search\Translators;

use Modules\Core\Search\Contracts\ISchemaTranslator;
use Modules\Core\Search\Schema\FieldDefinition;
use Modules\Core\Search\Schema\FieldType;
use Modules\Core\Search\Schema\IndexType;
use Modules\Core\Search\Schema\SchemaDefinition;

class TypesenseTranslator implements ISchemaTranslator
{
    public function translate(SchemaDefinition $schema): array
    {
        $collection = [
            'name' => $schema->name,
            'fields' => [],
        ];

        $requires_nested = false;

        foreach ($schema->getFields() as $field) {
            $translated = $this->translateField($field);
            $collection['fields'][] = $translated;

            if ($translated['type'] === 'object' || $translated['type'] === 'object[]') {
                $requires_nested = true;
            }

            foreach ($this->translateNestedFields($field) as $nestedField) {
                $collection['fields'][] = $nestedField;
            }
        }

        if ($requires_nested) {
            $collection['enable_nested_fields'] = true;
        }

        return $collection;
    }

    public function getEngineName(): string
    {
        return 'typesense';
    }

    private function translateField(FieldDefinition $field): array
    {
        $tsField = [
            'name' => $field->name,
            // A top-level date is a number on Typesense, which filters ranges on numbers only: TypesenseEngine indexes
            // and filters it as seconds since the epoch. A date nested in an object stays a string.
            'type' => $field->type === FieldType::Date ? 'int64' : $this->getTypesenseType($field->type, $field->options),
        ];

        // Add index-specific options
        if ($field->hasIndexType(IndexType::Searchable)) {
            $tsField['index'] = true;
        }

        if ($field->hasIndexType(IndexType::Filterable) || $field->hasIndexType(IndexType::Facetable)) {
            $tsField['facet'] = true;
        }

        if ($field->hasIndexType(IndexType::Sortable)) {
            $tsField['sort'] = true;
        }

        return $tsField;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function translateNestedFields(FieldDefinition $field): array
    {
        if (! isset($field->options['properties']) || ! is_array($field->options['properties'])) {
            return [];
        }

        $fields = [];
        $isArray = $field->type === FieldType::Array;

        foreach ($field->options['properties'] as $name => $definition) {
            if (! is_string($name)) {
                continue;
            }

            [$type, $filterable] = $this->nestedPropertyDefinition($definition);
            $translated = [
                'name' => $field->name . '.' . $name,
                'type' => $this->getTypesenseType($type) . ($isArray && ! str_ends_with($this->getTypesenseType($type), '[]') ? '[]' : ''),
                'optional' => true,
            ];

            if ($filterable) {
                $translated['facet'] = true;
            }

            $fields[] = $translated;
        }

        return $fields;
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

    private function getTypesenseType(FieldType $type, array $options = []): string
    {
        return match ($type) {
            FieldType::Text, FieldType::Keyword => 'string',
            FieldType::Integer => 'int32',
            FieldType::Float => 'float',
            FieldType::Boolean => 'bool',
            FieldType::Date => 'string', // nested dates only: a top-level date is int64, see translateField()
            FieldType::Vector => 'float[]',
            FieldType::Array => isset($options['properties']) ? 'object[]' : 'string[]',
            FieldType::Object, FieldType::Geocode => 'object',
        };
    }
}
