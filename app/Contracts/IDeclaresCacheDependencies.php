<?php

declare(strict_types=1);

namespace Modules\Core\Contracts;

/**
 * Marks a model whose cached CRUD responses read tables the request does not name.
 *
 * A response is labelled with the tables of the relation paths its request names. Anything else a read of the model
 * touches must be declared here: the tables read by its global scopes, its default eager loads (`$with`), its
 * appended accessors and computed methods, and the relations its ACL filters may name. Each table becomes a set
 * label of every response that reads the model, as the main entity or through a relation, so a write to it
 * invalidates them.
 */
interface IDeclaresCacheDependencies
{
    /**
     * The tables, unprefixed as `Model::getTable()` returns them, that a read of the model depends on.
     *
     * @return list<string>
     */
    public function cacheDependencyTables(): array;
}
