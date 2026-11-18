<?php

declare(strict_types=1);

namespace Modules\Core\Search\Extensions;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\RelationFilter;
use Modules\Core\Casts\ScopeFilter;
use Modules\Core\Casts\Sort;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Contracts\IExtendableModel;
use Modules\Core\Contracts\IExtendsModel;
use Modules\Core\Search\Services\ScoutSearchConstraintApplier;

/**
 * Translates the filters, the ACL and the sort of an extender to the index of its host, by one rule
 * (spec `docs/superpowers/specs/2026-11-17-extended-entity-search-design.md`, XS10):
 *
 * - an attribute the extender mounts from its host maps to the host field of the same name;
 * - an own column of the extender maps to `extension.<column>` when the extender indexes it as filterable;
 * - anything else, or a field the host index does not filter on, cannot be translated.
 *
 * A request filter that cannot be translated refuses the search, as a filter on a field the index does not filter on
 * does for any model. An ACL condition that cannot be translated is left out of the engine, the way the constraint
 * applier leaves out a scope: the engine then selects more, never less, and the full ACL of the extender is applied
 * when the hits are reloaded. The ACL fails closed, at the cost of a possibly short page.
 *
 * Relation and scope filters of a request are kept as they are: the engine never receives them, and they are applied
 * to the extender when the hits are reloaded.
 */
final readonly class ExtenderFilterTranslator
{
    public function __construct(private ScoutSearchConstraintApplier $applier) {}

    /**
     * The host of an extender, which must be a host of extenders.
     *
     * @throws LogicException when the extender names a model that does not implement the host contract
     */
    public static function host(IExtendsModel $extender): Model&IExtendableModel
    {
        $class = $extender->extendedModel();
        $host = new $class();

        if (! $host instanceof Model || ! $host instanceof IExtendableModel) {
            throw new LogicException(sprintf('%s extends %s, which does not implement %s.', $extender::class, $class, IExtendableModel::class));
        }

        return $host;
    }

    /**
     * @param  FiltersGroup|null  $request  The filters of the request, in the extender's terms
     * @param  FiltersGroup|null  $acl  The resolved ACL filters of the user on the extender
     * @param  array<int, Sort>  $sort
     *
     * @throws InvalidArgumentException when a request filter or the sort cannot be translated
     */
    public function search(Model&IExtendsModel $extender, ?FiltersGroup $request, ?FiltersGroup $acl, array $sort): ExtenderSearch
    {
        $host = self::host($extender);
        $filters = array_values(array_filter([
            $request instanceof FiltersGroup ? $this->requestFilters($extender, $host, $request) : null,
            $acl instanceof FiltersGroup ? $this->aclFilters($extender, $host, $acl) : null,
        ]));

        return new ExtenderSearch(
            $host,
            $extender,
            $filters === [] ? null : new FiltersGroup($filters, WhereClause::And),
            $this->sort($extender, $host, $sort),
        );
    }

    /**
     * The host field an extender property filters on, or null when it cannot be translated.
     */
    public function field(Model&IExtendsModel $extender, Model $host, string $property): ?string
    {
        $name = $this->withoutTablePrefix($extender, $property);

        if (in_array($name, $extender->extensionOwnColumns(), true)) {
            if (! self::indexedAsFilterable($extender->searchableExtensionMapping()[$name] ?? null)) {
                return null;
            }

            $name = IExtendableModel::EXTENSION_FIELD . '.' . $name;
        }

        return $this->applier->isFilterable($host, $name) ? $name : null;
    }

    /**
     * @throws InvalidArgumentException when a filter cannot be translated
     */
    public function requestFilters(Model&IExtendsModel $extender, Model $host, FiltersGroup $filters): FiltersGroup
    {
        $translated = [];

        foreach ($filters->filters as $node) {
            $translated[] = match (true) {
                $node instanceof FiltersGroup => $this->requestFilters($extender, $host, $node),
                $node instanceof RelationFilter, $node instanceof ScopeFilter => $node,
                $node instanceof Filter => new Filter(
                    $this->field($extender, $host, $node->property) ?? throw $this->untranslatable($extender, $node->property),
                    $node->value,
                    $node->operator,
                ),
                default => throw self::unknownNode($node),
            };
        }

        return new FiltersGroup($translated, $filters->operator);
    }

    /**
     * The part of the ACL the host index can apply. A condition left out widens the engine hits, so it is dropped
     * from an AND group, and an OR group that loses one is dropped whole. Null when nothing is left.
     *
     * @throws InvalidArgumentException when the tree holds a node kind this translator does not know
     */
    public function aclFilters(Model&IExtendsModel $extender, Model $host, FiltersGroup $filters): ?FiltersGroup
    {
        $kept = [];

        foreach ($filters->filters as $node) {
            $translated = match (true) {
                $node instanceof FiltersGroup => $this->aclFilters($extender, $host, $node),
                $node instanceof RelationFilter, $node instanceof ScopeFilter => null,
                $node instanceof Filter => ($field = $this->field($extender, $host, $node->property)) !== null
                    ? new Filter($field, $node->value, $node->operator)
                    : null,
                default => throw self::unknownNode($node),
            };

            if ($translated === null) {
                if ($filters->operator === WhereClause::Or) {
                    return null;
                }

                continue;
            }

            $kept[] = $translated;
        }

        return $kept === [] ? null : new FiltersGroup($kept, $filters->operator);
    }

    /**
     * The sort on the host index. An attribute mounted from the host sorts by the host field; an own column of the
     * extender does not sort a search.
     *
     * @param  array<int, Sort>  $sort
     * @return list<Sort>
     *
     * @throws InvalidArgumentException when a sort property is an own column of the extender
     */
    public function sort(Model&IExtendsModel $extender, Model $host, array $sort): array
    {
        $translated = [];

        foreach ($sort as $item) {
            $name = $this->withoutTablePrefix($extender, $item->property);

            if (in_array($name, $extender->extensionOwnColumns(), true)) {
                throw new InvalidArgumentException(ScoutSearchConstraintApplier::SORT_ERROR);
            }

            $translated[] = new Sort($name, $item->direction);
        }

        return $translated;
    }

    private static function indexedAsFilterable(mixed $definition): bool
    {
        return is_array($definition)
            && (($definition['filterable'] ?? false) === true || ($definition['facet'] ?? false) === true);
    }

    private static function unknownNode(mixed $node): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('The search engine cannot take a %s filter node.', get_debug_type($node)));
    }

    private function withoutTablePrefix(Model $extender, string $property): string
    {
        $prefix = $extender->getTable() . '.';

        return str_starts_with($property, $prefix) ? mb_substr($property, mb_strlen($prefix)) : $property;
    }

    private function untranslatable(Model&IExtendsModel $extender, string $property): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'The search of %s cannot filter on "%s": it searches the index of its host, which filters only on the indexed attributes of the host and on the own columns the extender indexes in `extension` (XS10).',
            $extender->getTable(),
            $property,
        ));
    }
}
