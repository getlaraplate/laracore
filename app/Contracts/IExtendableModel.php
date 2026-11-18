<?php

declare(strict_types=1);

namespace Modules\Core\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A host of extenders: a model whose rows other models can extend, each extender in its own table, keyed to the host
 * row it owns ({@see IExtendsModel}). A row owned by an extender carries the extender's alias in the host's type
 * column, a plain row carries null.
 *
 * Extended rows are hidden from every default read of the host and kept out of every search of it: Core derives the
 * `<type column> IS NULL` exclusion from this contract, the host declares no default search filter for it. The
 * generic implementation is {@see \Modules\Core\Models\Concerns\HasExtensions}.
 * Spec `docs/superpowers/specs/2026-11-17-extended-entity-search-design.md`, XS11 and XS13.
 */
interface IExtendableModel
{
    /**
     * The key of the search document under which the host writes {@see self::extensionSearchDocument()} and the
     * mapping declares {@see self::extensionSearchMapping()}: a filter on an extender's indexed column is a filter
     * on `extension.<column>`.
     */
    public const string EXTENSION_FIELD = 'extension';

    /**
     * The column holding, on a row an extender owns, that extender's alias; null on a plain row.
     */
    public function extensionTypeColumn(): string;

    /**
     * The extenders registered for this host.
     *
     * @return array<string, class-string<Model&IExtendsModel>> alias => extender class
     */
    public function extenders(): array;

    /**
     * A query on this host that includes the rows extenders own.
     *
     * @return Builder<Model>
     */
    public function queryWithExtensions(): Builder;

    /**
     * The rows of a page, each extended row replaced by its extender with the host row attached to it.
     *
     * @param  Collection<int, Model>  $rows
     * @return Collection<int, Model>
     */
    public function upcastExtensions(Collection $rows): Collection;

    /**
     * The `extension` section of this row's search document: the alias as `type` and the extender's
     * {@see IExtendsModel::searchableExtension()}. Null for a plain row.
     *
     * @return array<string, mixed>|null
     */
    public function extensionSearchDocument(): ?array;

    /**
     * The properties of the `extension` object of the search mapping, composed from every registered extender.
     *
     * @return array<string, mixed>
     */
    public function extensionSearchMapping(): array;
}
