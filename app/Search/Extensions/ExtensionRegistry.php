<?php

declare(strict_types=1);

namespace Modules\Core\Search\Extensions;

use InvalidArgumentException;
use Modules\Core\Contracts\IExtendsModel;

/**
 * The extenders of each host, by alias. Code, never a table: each extender's module registers it at boot. Keyed per
 * host, so an alias registered for one host is unknown to another. Generalised from CMS `ContentExtenderRegistry`.
 * Spec `docs/superpowers/specs/2026-11-17-extended-entity-search-design.md`, XS13.
 */
final class ExtensionRegistry
{
    /**
     * @var array<string, array<string, class-string<IExtendsModel>>> host class => alias => extender class
     */
    private array $extenders = [];

    /**
     * @param  class-string  $host
     * @param  class-string  $extender
     *
     * @throws InvalidArgumentException when the extender does not implement {@see IExtendsModel}
     */
    public function register(string $host, string $alias, string $extender): void
    {
        if (! is_subclass_of($extender, IExtendsModel::class)) {
            throw new InvalidArgumentException(sprintf('The extender [%s] registered as [%s] for [%s] does not implement %s.', $extender, $alias, $host, IExtendsModel::class));
        }

        $this->extenders[$host][$alias] = $extender;
    }

    /**
     * @param  class-string  $host
     */
    public function has(string $host, string $alias): bool
    {
        return isset($this->extenders[$host][$alias]);
    }

    /**
     * @param  class-string  $host
     * @return class-string<IExtendsModel>
     *
     * @throws InvalidArgumentException when the alias is not registered for the host
     */
    public function resolve(string $host, string $alias): string
    {
        if (! $this->has($host, $alias)) {
            throw new InvalidArgumentException(sprintf('No extender registered for alias [%s] of [%s].', $alias, $host));
        }

        return $this->extenders[$host][$alias];
    }

    /**
     * @param  class-string  $host
     * @return array<string, class-string<IExtendsModel>> alias => extender class, in registration order
     */
    public function extenders(string $host): array
    {
        return $this->extenders[$host] ?? [];
    }

    /**
     * Every registered extender, by host.
     *
     * @return array<string, array<string, class-string<IExtendsModel>>> host class => alias => extender class
     */
    public function all(): array
    {
        return $this->extenders;
    }

    /**
     * @param  class-string  $host
     * @return list<string>
     */
    public function aliases(string $host): array
    {
        return array_keys($this->extenders($host));
    }
}
