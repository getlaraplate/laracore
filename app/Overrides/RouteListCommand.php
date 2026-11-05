<?php

declare(strict_types=1);

namespace Modules\Core\Overrides;

use Illuminate\Foundation\Console\RouteListCommand as BaseRouteListCommand;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'route:list')]
final class RouteListCommand extends BaseRouteListCommand
{
    protected $description = 'List all registered routes <fg=green>(⚡ Modules\Core)</fg=green>';

    public function __construct(Router $router)
    {
        parent::__construct($router);

        /**
         * Laravel 13 declares `route:list` options in `$signature`, which bypasses `getOptions()`
         * entirely. Overriding that method, as this class did on Laravel 12, left `--internals`
         * unregistered and every invocation failed with "The \"internals\" option does not exist".
         * Adding it to the resolved definition works whichever mechanism the parent uses.
         */
        $this->getDefinition()->addOption(
            new InputOption('internals', null, InputOption::VALUE_NONE, 'Only show routes prefixed with app or api'),
        );
    }

    /**
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>|null
     */
    #[Override]
    protected function filterRoute(array $route): ?array
    {
        $route = parent::filterRoute($route);

        if ($route === null || ! $this->option('internals')) {
            return $route;
        }

        return Str::startsWith((string) $route['uri'], ['app', 'api'])
            ? $route
            : null;
    }
}
