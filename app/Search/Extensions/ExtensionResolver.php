<?php

declare(strict_types=1);

namespace Modules\Core\Search\Extensions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Core\Contracts\IExtendableModel;
use Modules\Core\Contracts\IExtendsModel;
use RuntimeException;

/**
 * The batched upcast of a page of host rows: each extended row is replaced by its extender, with the host row
 * re-attached through the extender's host relation. One query per distinct alias on the page, never one per row.
 * An alias that is not registered fails loud, and so does an extended row with no extender row (a data-integrity
 * error). Generalised from CMS `ContentExtensionResolver`.
 * Spec `docs/superpowers/specs/2026-11-17-extended-entity-search-design.md`, XS13.
 */
final readonly class ExtensionResolver
{
    public function __construct(private ExtensionRegistry $registry) {}

    /**
     * @param  Model&IExtendableModel  $host
     * @param  Collection<int, Model>  $rows
     * @return Collection<int, Model>
     */
    public function upcast(IExtendableModel $host, Collection $rows): Collection
    {
        $type_column = $host->extensionTypeColumn();

        /** @var array<string, list<int|string>> $ids_by_alias */
        $ids_by_alias = [];

        foreach ($rows as $row) {
            $alias = $row->getAttribute($type_column);
            $key = $row->getKey();

            if (is_string($alias) && $alias !== '' && (is_int($key) || is_string($key))) {
                $ids_by_alias[$alias][] = $key;
            }
        }

        /** @var array<string, Model> $extenders_by_host_key */
        $extenders_by_host_key = [];

        /** @var array<string, string> $relations */
        $relations = [];

        foreach ($ids_by_alias as $alias => $host_ids) {
            $class = $this->registry->resolve($host::class, $alias);
            /** @var Model&IExtendsModel $prototype */
            $prototype = new $class();
            $host_key = $prototype->extensionHostKey();
            $relations[$alias] = $prototype->extensionHostRelation();

            // The host relation is not loaded again: the page's host row is re-attached below.
            foreach ($class::query()->without($relations[$alias])->whereIn($host_key, $host_ids)->get() as $extender) {
                $host_id = $extender->getAttribute($host_key);

                if (is_int($host_id) || is_string($host_id)) {
                    $extenders_by_host_key[(string) $host_id] = $extender;
                }
            }
        }

        return $rows->map(static function (Model $row) use ($host, $type_column, $extenders_by_host_key, $relations): Model {
            $alias = $row->getAttribute($type_column);
            $key = $row->getKey();

            if (! is_string($alias) || $alias === '' || (! is_int($key) && ! is_string($key))) {
                return $row;
            }

            $extender = $extenders_by_host_key[(string) $key] ?? null;

            if (! $extender instanceof Model) {
                throw new RuntimeException(sprintf('Extended row [%s] of [%s] (alias [%s]) has no extender row.', $key, $host::class, $alias));
            }

            $extender->setRelation($relations[$alias], $row);

            return $extender;
        });
    }
}
