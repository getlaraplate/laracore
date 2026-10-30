<?php

declare(strict_types=1);

namespace Modules\Core\Overrides {
    function routes(bool $with_details = true, ?string $module = null): array
    {
        return $GLOBALS['__module_doc_routes'] ?? [];
    }
}

namespace {
    use Illuminate\Routing\Route;
    use Modules\Core\Overrides\ModuleDocGenerator;
    use Modules\Core\Overrides\ModuleDocRoute;
    use Modules\Core\Tests\Stubs\DummyFormRequest;
    use Modules\Core\Tests\Stubs\Overrides\AbstractModuleDocFormRequest;

    it('generateCombinations returns empty combination for empty parameters', function (): void {
        $config = ['ignoredRoutes' => []];
        $generator = new ModuleDocGenerator($config, 'Modules\\Core', null);

        $ref = new ReflectionMethod(ModuleDocGenerator::class, 'generateCombinations');

        $result = $ref->invoke($generator, []);

        expect($result)->toBe([[]]);
    });

    it('generateCombinations builds cartesian product of parameter values', function (): void {
        $config = ['ignoredRoutes' => []];
        $generator = new ModuleDocGenerator($config, 'Modules\\Core', null);

        $ref = new ReflectionMethod(ModuleDocGenerator::class, 'generateCombinations');

        $params = [
            'status' => ['draft', 'published'],
            'locale' => ['en', 'it'],
        ];

        $result = $ref->invoke($generator, $params);

        expect($result)->toHaveCount(4);
        expect($result)->toContain(
            ['status' => 'draft', 'locale' => 'en'],
            ['status' => 'draft', 'locale' => 'it'],
            ['status' => 'published', 'locale' => 'en'],
            ['status' => 'published', 'locale' => 'it'],
        );
    });

    it('iterateWheres collects parameter values from wheres array', function (): void {
        $config = ['ignoredRoutes' => []];
        $generator = new ModuleDocGenerator($config, 'Modules\\Core', null);

        $ref = new ReflectionMethod(ModuleDocGenerator::class, 'iterateWheres');

        $wheres = [
            'status' => 'draft|published',
            'locale' => ['en', 'it'],
        ];

        $parameter_values = [];
        $ref->invokeArgs($generator, [$wheres, &$parameter_values]);

        // iterateWheres accetta solo string|array, quindi con questi input
        // non ci sono esclusioni e otteniamo le liste attese.
        expect($parameter_values)->toHaveKey('status')
            ->and($parameter_values['status'])->toBe(['draft', 'published'])
            ->and($parameter_values)->toHaveKey('locale')
            ->and($parameter_values['locale'])->toBe(['en', 'it']);
    });

    it('shouldIgnoreRoute returns true for ignored route names and patterns', function (): void {
        $config = [
            'ignoredRoutes' => [
                'health.check',
                'debug*',
            ],
        ];
        $generator = new ModuleDocGenerator($config, 'Modules\\Core', null);

        $ref = new ReflectionMethod(ModuleDocGenerator::class, 'shouldIgnoreRoute');

        $route = new Route(['GET'], '/debug/info', ['as' => 'debug.info']);
        $route2 = new Route(['GET'], '/health/status', ['as' => 'health.check']);
        $route3 = new Route(['GET'], '/app/home', ['as' => 'app.home']);

        expect($ref->invoke($generator, $route))->toBeTrue()
            ->and($ref->invoke($generator, $route2))->toBeTrue()
            ->and($ref->invoke($generator, $route3))->toBeFalse();
    });

    it('iterateCombinations clones routes and replaces parameters', function (): void {
        $config = ['ignoredRoutes' => []];
        $generator = new ModuleDocGenerator($config, 'Modules\\Core', null);

        // Simula una route con parametri in wheres come atteso dal generator
        $route = new Route(['GET'], '/posts/{status}/{locale}', ['as' => 'posts.index']);
        $route->wheres = [
            'status' => ['draft', 'published'],
            'locale' => ['en', 'it'],
        ];

        $parameter_values = $route->wheres;

        $module_routes = [];

        $ref = new ReflectionMethod(ModuleDocGenerator::class, 'iterateCombinations');

        $ref->invokeArgs($generator, [$route, $parameter_values, &$module_routes]);

        expect($module_routes)->toHaveCount(4);
        expect($module_routes[0])->toBeInstanceOf(ModuleDocRoute::class);
    });

    it('getFormRules returns rules from non-abstract FormRequest parameter', function (): void {
        $config = ['ignoredRoutes' => []];
        $generator = new ModuleDocGenerator($config, 'Modules\\Core', null);

        $controller = new class()
        {
            public function store(DummyFormRequest $request): void {}
        };

        // Imposta la route in modo compatibile con Str::parseCallback(action())
        $route = new Route(['POST'], '/demo', ['uses' => $controller::class . '@store']);

        $route_prop = new ReflectionProperty(ModuleDocGenerator::class, 'route');
        $route_prop->setValue($generator, $route);

        $method_prop = new ReflectionProperty(ModuleDocGenerator::class, 'method');
        $method_prop->setValue($generator, 'post');

        // Aggiunge una macro "action" per rendere compatibile la chiamata in getActionClassInstance
        Route::macro('action', function () use ($route) {
            return $route->getAction('uses');
        });

        $ref = new ReflectionMethod(ModuleDocGenerator::class, 'getFormRules');

        $rules = $ref->invoke($generator);

        expect($rules)->toBe(['name' => 'required|string']);
    });

    it('getAppRoutes expands wheres combinations and ignores configured routes', function (): void {
        $config = ['ignoredRoutes' => ['ignored.*']];
        $generator = new ModuleDocGenerator($config, 'Modules\\Core', null);

        $plain = new Route(['GET'], '/app/plain', ['as' => 'app.plain']);
        $with_wheres = new Route(['GET'], '/api/posts/{status}', ['as' => 'posts.index']);
        $with_wheres->wheres = ['status' => 'draft|published'];
        $ignored = new Route(['GET'], '/api/ignored', ['as' => 'ignored.route']);

        $GLOBALS['__module_doc_routes'] = [$plain, $with_wheres, $ignored];

        $ref = new ReflectionMethod(ModuleDocGenerator::class, 'getAppRoutes');
        $routes = $ref->invoke($generator);

        expect($routes)->toHaveCount(3)
            ->and($routes[0])->toBeInstanceOf(ModuleDocRoute::class);
    });

    it('getAppRoutes ignores redirect and view routes', function (): void {
        $generator = new ModuleDocGenerator(['ignoredRoutes' => []], 'Modules\\Core', null);

        $redirect = new Route(['GET', 'POST'], '/', '\\Illuminate\\Routing\\RedirectController');
        $view = new Route(['GET'], '/landing', '\\Illuminate\\Routing\\ViewController');
        $endpoint = new Route(['GET'], '/api/plain', ['as' => 'api.plain']);

        $GLOBALS['__module_doc_routes'] = [$redirect, $view, $endpoint];

        $ref = new ReflectionMethod(ModuleDocGenerator::class, 'getAppRoutes');
        $routes = $ref->invoke($generator);

        expect($routes)->toHaveCount(1)
            ->and($routes[0]->uri())->toBe('/api/plain');
    });

    it('generatePath sets operationId and up route html content', function (): void {
        $config = [
            'ignoredRoutes' => [],
            'parseDocBlock' => false,
        ];
        $generator = new ModuleDocGenerator($config, 'Modules\\Core', null);
        $controller = new class()
        {
            public function health(): void {}
        };
        $route = new Route(['GET'], '/up', ['uses' => $controller::class . '@health', 'as' => 'health']);

        (new ReflectionProperty(ModuleDocGenerator::class, 'route'))->setValue($generator, new ModuleDocRoute($route));
        (new ReflectionProperty(ModuleDocGenerator::class, 'method'))->setValue($generator, 'get');
        (new ReflectionProperty(ModuleDocGenerator::class, 'docs'))->setValue($generator, ['paths' => []]);

        $method = new ReflectionMethod(ModuleDocGenerator::class, 'generatePath');
        $method->invoke($generator);

        $docs = (new ReflectionProperty(ModuleDocGenerator::class, 'docs'))->getValue($generator);
        $entry = $docs['paths']['/up']['get'];

        expect($entry)->toHaveKey('operationId')
            ->and($entry)->toHaveKey('tags')
            ->and($entry['responses']['200']['content'])->toHaveKey('text/html');
    });

    it('getFormRules skips abstract and built-in typed parameters', function (): void {
        $config = ['ignoredRoutes' => []];
        $generator = new ModuleDocGenerator($config, 'Modules\\Core', null);

        $controller = new class()
        {
            public function store(AbstractModuleDocFormRequest $request, string $id): void {}
        };
        $route = new Route(['POST'], '/demo', ['uses' => $controller::class . '@store']);

        Route::macro('action', fn () => $route->getAction('uses'));
        (new ReflectionProperty(ModuleDocGenerator::class, 'route'))->setValue($generator, $route);
        (new ReflectionProperty(ModuleDocGenerator::class, 'method'))->setValue($generator, 'post');

        $ref = new ReflectionMethod(ModuleDocGenerator::class, 'getFormRules');
        $rules = $ref->invoke($generator);

        expect($rules)->toBe([]);
    });

    it('shouldIgnoreRoute returns false when ignoredRoutes is not configured', function (): void {
        $generator = new ModuleDocGenerator([], 'Modules\\Core', null);
        $route = new Route(['GET'], '/app/home', ['as' => 'app.home']);
        $ref = new ReflectionMethod(ModuleDocGenerator::class, 'shouldIgnoreRoute');

        expect($ref->invoke($generator, $route))->toBeFalse();
    });

    it('shouldIgnoreRoute ignores unnamed underscore and static asset routes', function (): void {
        $generator = new ModuleDocGenerator(['ignoredRoutes' => []], 'Modules\\Core', null);
        $ref = new ReflectionMethod(ModuleDocGenerator::class, 'shouldIgnoreRoute');
        $underscore = new Route(['GET'], '/_debug', ['as' => '']);
        $asset = new Route(['GET'], '/app.js', ['as' => 'asset']);

        expect($ref->invoke($generator, $underscore))->toBeTrue()
            ->and($ref->invoke($generator, $asset))->toBeTrue();
    });

    it('getAppRoutes keeps route when wheres exist but no valid parameter values', function (): void {
        $config = ['ignoredRoutes' => []];
        $generator = new ModuleDocGenerator($config, 'Modules\\Core', null);
        $route = new Route(['GET'], '/api/item/{id}', ['as' => 'items.show']);
        $route->wheres = ['id' => 123];
        $GLOBALS['__module_doc_routes'] = [$route];

        $ref = new ReflectionMethod(ModuleDocGenerator::class, 'getAppRoutes');
        $routes = $ref->invoke($generator);

        expect($routes)->toHaveCount(1)
            ->and($routes[0])->toBeInstanceOf(ModuleDocRoute::class);
    });

    it('iterateWheres ignores invalid non string and non array where values', function (): void {
        $generator = new ModuleDocGenerator(['ignoredRoutes' => []], 'Modules\\Core', null);
        $ref = new ReflectionMethod(ModuleDocGenerator::class, 'iterateWheres');
        $parameter_values = [];

        $ref->invokeArgs($generator, [['id' => 12345], &$parameter_values]);

        expect($parameter_values)->toBe([]);
    });

    it('getFormRules skips parameters with union type', function (): void {
        $generator = new ModuleDocGenerator(['ignoredRoutes' => []], 'Modules\\Core', null);
        $controller = new class()
        {
            public function store(DummyFormRequest|string $request): void {}
        };
        $route = new Route(['POST'], '/demo', ['uses' => $controller::class . '@store']);

        Route::macro('action', fn () => $route->getAction('uses'));
        (new ReflectionProperty(ModuleDocGenerator::class, 'route'))->setValue($generator, $route);
        (new ReflectionProperty(ModuleDocGenerator::class, 'method'))->setValue($generator, 'post');

        $ref = new ReflectionMethod(ModuleDocGenerator::class, 'getFormRules');
        $rules = $ref->invoke($generator);

        expect($rules)->toBe([]);
    });
}
