<?php

declare(strict_types=1);

namespace Modules\Core\Overrides;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\RedirectController;
use Illuminate\Routing\Route;
use Illuminate\Routing\ViewController;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mtrajano\LaravelSwagger\Generator;
use Override;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

final class ModuleDocGenerator extends Generator
{
    public function __construct(mixed $config, private readonly string $module, mixed $routeFilter = null)
    {
        parent::__construct($config, $routeFilter);
    }

    /**
     * @return array<int, ModuleDocRoute>
     */
    #[Override]
    protected function getAppRoutes(): array
    {
        $module = Str::replace('Modules\\', '', $this->module);
        $all_module_routes = routes(true, $module);
        $module_routes = [];

        foreach ($all_module_routes as $route) {
            if ($this->shouldIgnoreRoute($route)) {
                continue;
            }

            if (! $route->wheres) {
                $module_routes[] = new ModuleDocRoute($route);

                continue;
            }

            $parameter_values = [];

            $this->iterateWheres($route->wheres, $parameter_values);

            if ($parameter_values === []) {
                $module_routes[] = new ModuleDocRoute($route);

                continue;
            }

            $this->iterateCombinations($route, $parameter_values, $module_routes);
        }

        return $module_routes;
    }

    /**
     * Generates the path for the route.
     *
     * @throws InvalidArgumentException
     */
    #[Override]
    protected function generatePath(): void
    {
        parent::generatePath();
        $uri = $this->route->uri();
        $operationId = $this->method . str_replace(['/', '{', '}'], ['-', '', ''], $uri);
        $group = Str::contains($uri, '/app/') ? 'App' : (Str::contains($uri, '/api/') ? 'Api' : 'Others');
        $path_method = &$this->docs['paths'][$this->route->uri()][$this->method];
        $path_method['operationId'] = $operationId;
        $path_method['tags'] = array_unique([$group, Str::replace('Modules\\', '', $this->module)]);

        if ($this->route->uri() === '/up') {
            $path_method['responses']['200']['content'] = [
                'text/html' => [],
            ];
        }
    }

    /**
     * Override to handle abstract FormRequest classes.
     *
     * @return array<string, array<int, string>|string>
     */
    #[Override]
    protected function getFormRules(): array
    {
        $action_instance = $this->getActionClassInstance();

        if (! $action_instance instanceof ReflectionMethod) {
            return [];
        }

        $parameters = $action_instance->getParameters();

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType) {
                continue;
            }

            if ($type->isBuiltin()) {
                continue;
            }

            $class_name = $type->getName();

            if (is_subclass_of($class_name, FormRequest::class)) {
                $reflection = new ReflectionClass($class_name);

                // Skip abstract classes as they cannot be instantiated
                if ($reflection->isAbstract()) {
                    continue;
                }

                return (new $class_name)->rules();
            }
        }

        return [];
    }

    /**
     * Get the action class instance as ReflectionMethod.
     * Replicates the private method from parent class.
     */
    private function getActionClassInstance(): ?ReflectionMethod
    {
        [$class, $method] = Str::parseCallback($this->route->action());

        if (! $class || ! $method) {
            return null;
        }

        return new ReflectionMethod($class, $method);
    }

    /**
     * Iterates through the wheres array and adds the parameter values to the parameter_values array.
     *
     * @param  array<string, string|array>  $wheres
     * @param  array<string, array<int, string>>  &$parameter_values
     */
    private function iterateWheres(array $wheres, array &$parameter_values): void
    {
        foreach ($wheres as $key => $value) {
            if (! is_string($value) && ! is_array($value)) {
                continue;
            }

            $values = is_string($value) && Str::contains($value, '|')
                ? explode('|', $value)
                : (array) $value;

            if ($values !== []) {
                $parameter_values[$key] = $values;
            }
        }
    }

    /**
     * Iterates through the combinations and adds the new routes to the module_routes array.
     *
     * @param  array<string, array<int, string>>  $parameter_values
     * @param  array<int, ModuleDocRoute>  $module_routes
     */
    private function iterateCombinations(Route $route, array $parameter_values, array &$module_routes): void
    {
        foreach ($this->generateCombinations($parameter_values) as $combination) {
            $new_route = clone $route;
            $new_uri = $new_route->uri;

            foreach ($combination as $param => $value) {
                $new_uri = str_replace('{' . $param . '}', $value, $new_uri);
                unset($new_route->wheres[$param]);
            }

            $new_route->uri = $new_uri;
            $module_routes[] = new ModuleDocRoute($new_route);
        }
    }

    private function shouldIgnoreRoute(Route $route): bool
    {
        if (! isset($this->config['ignoredRoutes'])) {
            return false;
        }

        $route_name = $route->getName();
        $route_uri = $route->uri();

        if ($this->isRedirectOrViewRoute($route)) {
            return true;
        }

        if ($route_name === '' || Str::startsWith($route_uri, ['_', '/_']) || Str::endsWith($route_uri, ['.map', '.js', '.css', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ico', '.webp', '.woff', '.woff2', '.ttf', '.eot', '.otf', '.ico', '.webp', '.woff', '.woff2', '.ttf', '.eot', '.otf'])) {
            return true;
        }

        foreach ($this->config['ignoredRoutes'] as $pattern) {
            if (! Str::contains($pattern, '*')) {
                if ($route_name === $pattern) {
                    return true;
                }

                continue;
            }

            $regex = '/^' . str_replace('\*', '.*', preg_quote((string) $pattern, '/')) . '$/';

            if (preg_match($regex, (string) $route_name) || preg_match($regex, (string) $route_uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Routes declared with Route::redirect() or Route::view() serve web pages, not API endpoints.
     */
    private function isRedirectOrViewRoute(Route $route): bool
    {
        $controller = mb_ltrim((string) $route->getControllerClass(), '\\');

        return in_array($controller, [RedirectController::class, ViewController::class], true);
    }

    /**
     * Generates all possible combinations of parameter values.
     *
     * @param  array  $parameters  Array of parameters and their possible values
     * @return array Array of all possible combinations
     */
    private function generateCombinations(array $parameters): array
    {
        if ($parameters === []) {
            return [[]];
        }

        $param = key($parameters);
        $values = array_shift($parameters);

        $combinations = [];
        $sub_combinations = $this->generateCombinations($parameters);

        foreach ($values as $value) {
            foreach ($sub_combinations as $sub_combination) {
                $combinations[] = array_merge([$param => $value], $sub_combination);
            }
        }

        return $combinations;
    }
}
