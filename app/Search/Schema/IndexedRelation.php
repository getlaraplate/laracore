<?php

declare(strict_types=1);

namespace Modules\Core\Search\Schema;

use Illuminate\Database\Eloquent\Model;

/**
 * A relation indexed in a search document, declared once: the same declaration yields the mapping field
 * ({@see self::field()}) and the document entry ({@see self::document()}), so the two always carry the same keys.
 *
 * The default shape is the one taxonomy-like relations share: `name` (text), `id` (integer, filterable), `slug` and
 * `path` (keyword, filterable). Extra properties are added after it, and a property of the same name replaces the
 * default in place. Spec `docs/superpowers/specs/2026-11-17-extended-entity-search-design.md`, XS14.
 */
final readonly class IndexedRelation
{
    /**
     * @var array<string, FieldType|array{type: FieldType, filterable?: bool, facet?: bool}>
     */
    public const array DEFAULT_PROPERTIES = [
        'name' => FieldType::Text,
        'id' => ['type' => FieldType::Integer, 'filterable' => true],
        'slug' => ['type' => FieldType::Keyword, 'filterable' => true],
        'path' => ['type' => FieldType::Keyword, 'filterable' => true],
    ];

    /**
     * @param  string  $relation  The Eloquent relation the records come from, recorded on the field
     * @param  array<string, FieldType|array{type: FieldType, filterable?: bool, facet?: bool}>  $properties  Added to the default shape, or replacing a default of the same name
     * @param  list<IndexType>  $indexTypes
     * @param  string|null  $field  The document key; the relation name when null
     */
    public function __construct(
        public string $relation,
        public array $properties = [],
        public array $indexTypes = [IndexType::Searchable, IndexType::Filterable, IndexType::Facetable],
        public ?string $field = null,
    ) {}

    /**
     * The properties of each related record: the default shape, with the declared properties added or replacing.
     *
     * @return array<string, FieldType|array{type: FieldType, filterable?: bool, facet?: bool}>
     */
    public function properties(): array
    {
        return array_replace(self::DEFAULT_PROPERTIES, $this->properties);
    }

    public function field(): FieldDefinition
    {
        return new FieldDefinition($this->field ?? $this->relation, FieldType::Array, $this->indexTypes, [
            'relation' => $this->relation,
            'properties' => $this->properties(),
        ]);
    }

    /**
     * The document entry: each related record with exactly the declared properties, in their order.
     *
     * @param  iterable<int, Model>  $related
     * @return list<array<string, mixed>>
     */
    public function document(iterable $related): array
    {
        $keys = array_keys($this->properties());
        $entries = [];

        foreach ($related as $record) {
            $entries[] = $record->only($keys);
        }

        return $entries;
    }
}
