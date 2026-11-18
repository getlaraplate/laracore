<?php

declare(strict_types=1);

namespace Modules\Core\Search\Extensions;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Contracts\IExtendableModel;
use Modules\Core\Contracts\IExtendsModel;

/**
 * The extenders of a host whose rows a user may read in a list of the host opened with `with_extended`, each with the
 * user's ACL on it. Spec `docs/superpowers/specs/2026-11-17-extended-entity-search-design.md`:
 *
 * - only the aliases whose table the user may `select` are here; the others stay out of the list, silently (XS4);
 * - an extended row passes the ACL of the host, applied by the caller to every row, and the ACL of its own entity,
 *   applied here (XS5);
 * - the condition replaces the host's exclusion of extended rows, never joins it (XS11): a plain row, or a row of a
 *   readable alias whose extender row passes its ACL.
 */
final readonly class ReadableExtenders
{
    /**
     * @param  array<string, array{extender: Model&IExtendsModel, acl: FiltersGroup|null}>  $extenders  alias => extender and its resolved ACL
     */
    public function __construct(
        public Model&IExtendableModel $host,
        public array $extenders,
    ) {}

    /**
     * The condition on the host index in place of `<type column> IS NULL`: a plain row, or a row of a readable alias
     * with the part of the extender's ACL the index can apply ({@see ExtenderFilterTranslator::aclFilters()}); the
     * rest of that ACL holds when the hits are reloaded ({@see self::constrain()}).
     */
    public function hostRows(ExtenderFilterTranslator $translator): FiltersGroup
    {
        $type_column = $this->host->extensionTypeColumn();
        $branches = [new Filter($type_column, null, FilterOperator::Equals)];

        foreach ($this->extenders as $alias => ['extender' => $extender, 'acl' => $acl]) {
            $alias_rows = new Filter($type_column, $alias, FilterOperator::Equals);
            $engine_acl = $acl instanceof FiltersGroup ? $translator->aclFilters($extender, $this->host, $acl) : null;
            $branches[] = $engine_acl instanceof FiltersGroup ? new FiltersGroup([$alias_rows, $engine_acl], WhereClause::And) : $alias_rows;
        }

        return new FiltersGroup($branches, WhereClause::Or);
    }

    /**
     * Opens a query of the host to the rows of the readable aliases: the hiding scope is removed and replaced by
     * `<type column> IS NULL`, or `<type column> = <alias>` with an extender row keyed to the host row that passes the
     * extender's ACL.
     *
     * @param  Builder<Model>  $query
     * @param  Closure(Builder<Model>, FiltersGroup): void  $applyAcl  Applies an ACL to a query of the extender, as
     *                                                                  the CRUD list does (mounted attributes included)
     */
    public function constrain(Builder $query, Closure $applyAcl): void
    {
        $type_column = $this->host->qualifyColumn($this->host->extensionTypeColumn());
        $host_key = $this->host->getQualifiedKeyName();

        $query->withoutGlobalScope(HidesExtendedRows::class)->where(function (Builder $rows) use ($type_column, $host_key, $applyAcl): void {
            $rows->whereNull($type_column);

            foreach ($this->extenders as $alias => ['extender' => $extender, 'acl' => $acl]) {
                $owned = $extender->newQuery()
                    ->selectRaw('1')
                    ->whereColumn($extender->qualifyColumn($extender->extensionHostKey()), $host_key);

                if ($acl instanceof FiltersGroup) {
                    $applyAcl($owned, $acl);
                }

                $rows->orWhere(static function (Builder $branch) use ($type_column, $alias, $owned): void {
                    $branch->where($type_column, $alias)->addWhereExistsQuery($owned->toBase());
                });
            }
        });
    }

    /**
     * @return list<class-string<Model&IExtendsModel>>
     */
    public function extenderClasses(): array
    {
        return array_values(array_map(static fn (array $entry): string => $entry['extender']::class, $this->extenders));
    }
}
