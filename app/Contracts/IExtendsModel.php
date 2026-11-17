<?php

declare(strict_types=1);

namespace Modules\Core\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * An extender: a model in its own table that extends a row of a host ({@see IExtendableModel}), keyed to it by a
 * host key. Registered for its host under a stable alias in {@see \Modules\Core\Search\Extensions\ExtensionRegistry}.
 * Spec `docs/superpowers/specs/2026-11-17-extended-entity-search-design.md`, XS11.
 */
interface IExtendsModel
{
    /**
     * The host this model extends.
     *
     * @return class-string<Model&IExtendableModel>
     */
    public function extendedModel(): string;

    /**
     * The stable alias stored in the host's type column (convention `module.model`, e.g. `shop.product`).
     */
    public function extensionAlias(): string;

    /**
     * The column of this model holding the key of the host row it extends.
     */
    public function extensionHostKey(): string;

    /**
     * The relation of this model that returns the host row it extends.
     */
    public function extensionHostRelation(): string;

    /**
     * The columns this model owns; every other attribute it exposes belongs to the host row.
     *
     * @return list<string>
     */
    public function extensionOwnColumns(): array;

    /**
     * This model's own data in the host row's search document, under the `extension` object.
     *
     * @return array<string, mixed>
     */
    public function searchableExtension(): array;

    /**
     * The mapping of {@see self::searchableExtension()}: field => a Core `FieldType`, or
     * `['type' => FieldType, 'filterable' => bool, 'facet' => bool]`.
     *
     * @return array<string, mixed>
     */
    public function searchableExtensionMapping(): array;
}
