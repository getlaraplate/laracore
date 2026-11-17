<?php

declare(strict_types=1);

namespace Modules\Core\Search\Extensions;

use LogicException;
use Modules\Core\Search\Schema\FieldType;

/**
 * Two extenders of one host declare the same `extension` field with different definitions.
 *
 * All extenders of a host write into one `extension` object of one index mapping, where a field has one type. A
 * field is shared only when every declaration has the same type and the same `filterable` and `facet` flags; any
 * other overlap is refused when the mapping is composed, instead of silently keeping the first definition.
 * Spec `docs/superpowers/specs/2026-11-17-extended-entity-search-design.md`, XS16 (chosen 2026-11-17 over a prefix
 * per alias, to keep filter paths short).
 */
final class ExtensionFieldConflict extends LogicException
{
    /**
     * @param  array{type: FieldType, filterable: bool, facet: bool}  $first_definition
     * @param  array{type: FieldType, filterable: bool, facet: bool}  $second_definition
     */
    public static function between(
        string $host,
        string $field,
        string $first,
        array $first_definition,
        string $second,
        array $second_definition,
    ): self {
        return new self(sprintf(
            'The `extension` field "%s" of [%s] is declared as %s by %s and as %s by %s. Extenders of one host share an'
            . ' extension field only with the same type and flags (XS16, docs/superpowers/specs/2026-11-17-extended-entity-search-design.md):'
            . ' rename the field in one extender.',
            $field,
            $host,
            self::describe($first_definition),
            $first,
            self::describe($second_definition),
            $second,
        ));
    }

    /**
     * @param  array{type: FieldType, filterable: bool, facet: bool}  $definition
     */
    private static function describe(array $definition): string
    {
        $flags = array_keys(array_filter(['filterable' => $definition['filterable'], 'facet' => $definition['facet']]));

        return $definition['type']->value . ($flags === [] ? '' : ' (' . implode(', ', $flags) . ')');
    }
}
