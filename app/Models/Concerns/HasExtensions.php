<?php

declare(strict_types=1);

namespace Modules\Core\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Core\Contracts\IExtendsModel;
use Modules\Core\Search\Extensions\ExtensionFieldConflict;
use Modules\Core\Search\Extensions\ExtensionRegistry;
use Modules\Core\Search\Extensions\ExtensionResolver;
use Modules\Core\Search\Extensions\HidesExtendedRows;
use Modules\Core\Search\Schema\FieldType;

/**
 * The generic implementation of a host of extenders ({@see \Modules\Core\Contracts\IExtendableModel}): extended rows
 * hidden by default and opened with `withExtended()`, the extenders read from the {@see ExtensionRegistry} under the
 * host's class, the batched upcast, and the `extension` part of the search document and mapping. The using class
 * declares only its type column. Generalised from the host side of the CMS content-extension seam.
 * Spec `docs/superpowers/specs/2026-11-17-extended-entity-search-design.md`, XS13 and XS16.
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements \Modules\Core\Contracts\IExtendableModel
 */
trait HasExtensions
{
    public static function bootHasExtensions(): void
    {
        static::addGlobalScope(new HidesExtendedRows());
    }

    /**
     * @return array<string, class-string<IExtendsModel>>
     */
    public function extenders(): array
    {
        return resolve(ExtensionRegistry::class)->extenders(static::class);
    }

    /**
     * @return Builder<Model>
     */
    public function queryWithExtensions(): Builder
    {
        return $this->newQuery()->withoutGlobalScope(HidesExtendedRows::class);
    }

    /**
     * @param  Collection<int, Model>  $rows
     * @return Collection<int, Model>
     */
    public function upcastExtensions(Collection $rows): Collection
    {
        return resolve(ExtensionResolver::class)->upcast($this, $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function extensionSearchDocument(): ?array
    {
        $alias = $this->getAttribute($this->extensionTypeColumn());
        $registry = resolve(ExtensionRegistry::class);

        if (! is_string($alias) || $alias === '' || ! $registry->has(static::class, $alias)) {
            return null;
        }

        $class = $registry->resolve(static::class, $alias);
        /** @var Model&IExtendsModel $prototype */
        $prototype = new $class();
        $extender = $class::query()->where($prototype->extensionHostKey(), $this->getKey())->first();

        if (! $extender instanceof IExtendsModel) {
            return null;
        }

        return ['type' => $alias, ...$extender->searchableExtension()];
    }

    /**
     * The `extension` properties: the alias `type`, then the fields of every registered extender. A field declared
     * by two extenders, or by an extender as `type`, must have the same type and flags (XS16).
     *
     * @return array<string, mixed>
     *
     * @throws ExtensionFieldConflict when a field is declared with two different definitions
     */
    public function extensionSearchMapping(): array
    {
        $extenders = $this->extenders();

        if ($extenders === []) {
            return [];
        }

        $properties = ['type' => ['type' => FieldType::Keyword, 'filterable' => true]];
        $declared_by = ['type' => 'the host'];

        foreach ($extenders as $alias => $class) {
            /** @var Model&IExtendsModel $extender */
            $extender = new $class();

            foreach ($extender->searchableExtensionMapping() as $field => $definition) {
                if (! array_key_exists($field, $properties)) {
                    $properties[$field] = $definition;
                    $declared_by[$field] = $alias;

                    continue;
                }

                $existing = self::normalizedExtensionField($properties[$field]);
                $declared = self::normalizedExtensionField($definition);

                if ($existing !== $declared) {
                    throw ExtensionFieldConflict::between(static::class, (string) $field, $declared_by[$field], $existing, $alias, $declared);
                }
            }
        }

        return $properties;
    }

    /**
     * Opens the query to the rows extenders own: removes only the hiding scope, every other global scope stays.
     *
     * @param  Builder<Model>  $query
     */
    #[Scope]
    protected function withExtended(Builder $query): void
    {
        $query->withoutGlobalScope(HidesExtendedRows::class);
    }

    /**
     * A mapping declaration read the way the Elasticsearch translator reads nested properties.
     *
     * @return array{type: FieldType, filterable: bool, facet: bool}
     */
    private static function normalizedExtensionField(mixed $definition): array
    {
        if ($definition instanceof FieldType) {
            return ['type' => $definition, 'filterable' => false, 'facet' => false];
        }

        if (is_array($definition)) {
            $type = $definition['type'] ?? FieldType::Text;

            return [
                'type' => $type instanceof FieldType ? $type : FieldType::fromValue($type),
                'filterable' => ($definition['filterable'] ?? false) === true,
                'facet' => ($definition['facet'] ?? false) === true,
            ];
        }

        return ['type' => FieldType::fromValue($definition), 'filterable' => false, 'facet' => false];
    }
}
