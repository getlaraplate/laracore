<?php

declare(strict_types=1);

namespace Modules\Core\Search\Extensions;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\Sort;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Contracts\IExtendableModel;
use Modules\Core\Contracts\IExtendsModel;

/**
 * A search of an extender, ready to run on the index of its host: the host, the filters and sort translated to the
 * host index by {@see ExtenderFilterTranslator}, the alias condition that replaces the host's exclusion of extended
 * rows, and the column that reloads the hits as extenders.
 * Spec `docs/superpowers/specs/2026-11-17-extended-entity-search-design.md`, XS1, XS2 and XS10.
 */
final readonly class ExtenderSearch
{
    /**
     * @param  FiltersGroup|null  $filters  The request filters and the extender's ACL, translated to the host index
     * @param  list<Sort>  $sort  The request sort, translated to the host index
     */
    public function __construct(
        public Model&IExtendableModel $host,
        public Model&IExtendsModel $extender,
        public ?FiltersGroup $filters,
        public array $sort,
    ) {}

    /**
     * The rows of the host index the extender owns: `<type column> = <alias>`, in place of `<type column> IS NULL`.
     */
    public function hostRows(): Filter
    {
        return new Filter($this->host->extensionTypeColumn(), $this->extender->extensionAlias(), FilterOperator::Equals);
    }

    /**
     * The alias condition and the translated filters, as one tree for the constraint applier of the basic path.
     */
    public function engineFilters(): FiltersGroup
    {
        return new FiltersGroup(
            $this->filters instanceof FiltersGroup ? [$this->hostRows(), $this->filters] : [$this->hostRows()],
            WhereClause::And,
        );
    }

    /**
     * The extender column holding the key of its host row: a hit of the host index is the extender whose column
     * equals the hit key. With a shared key (XS7) it equals the extender's own key too; an extender with its own
     * key (XS8) is found the same way.
     */
    public function hitKeyColumn(): string
    {
        return $this->extender->extensionHostKey();
    }
}
