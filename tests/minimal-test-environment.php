<?php

declare(strict_types=1);

/**
 * Lightweight bindings for unit tests that use Modules\Core\Tests\TestCase (no Orchestra).
 * Composer autoload must already be loaded (phpunit.xml bootstrap = vendor/autoload.php).
 * Load it once per process: it returns the container, which TestCase makes global before every test.
 */

/*
|--------------------------------------------------------------------------
| Minimal container for standalone testing
|--------------------------------------------------------------------------
|
| Sets up a lightweight container with just enough bindings so that
| Laravel helpers (now(), config(), …) work without booting a full app.
|
*/

$container = new Illuminate\Container\Container();

$container->singleton('config', static fn (): Illuminate\Config\Repository => new Illuminate\Config\Repository([
    'app' => ['locale' => 'en', 'timezone' => 'UTC', 'fallback_locale' => 'en'],
]));

$container->singleton('date', static fn (): Illuminate\Support\DateFactory => new Illuminate\Support\DateFactory());

$container->singleton('app', static function () use ($container): object {
    return new class($container)
    {
        public function __construct(private readonly Illuminate\Container\Container $container) {}

        public function getLocale(): string
        {
            return $this->container->make('config')->get('app.locale', 'en');
        }

        public function setLocale(string $locale): void
        {
            $this->container->make('config')->set('app.locale', $locale);
        }

        /**
         * Minimal stub for app()->path($path) used by app_path() helper.
         */
        public function path(string $path = ''): string
        {
            $base = dirname(__DIR__);

            return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . mb_ltrim($path, DIRECTORY_SEPARATOR);
        }
    };
});

Illuminate\Support\Facades\Facade::setFacadeApplication($container);
Illuminate\Container\Container::setInstance($container);

// Register test fixture namespaces that the Composer autoloader doesn't know about
spl_autoload_register(static function (string $class): void {
    $map = [
        'Modules\\Fake\\Models\\' => __DIR__ . '/Fixtures/',
    ];

    foreach ($map as $prefix => $base_dir) {
        if (str_starts_with($class, $prefix)) {
            $relative = str_replace('\\', '/', mb_substr($class, mb_strlen($prefix)));
            $file = $base_dir . $relative . '.php';

            if (file_exists($file)) {
                require $file;
            }

            return;
        }
    }
});

if (! function_exists('module_path')) {
    /**
     * Standalone stub: resolves paths relative to the module root.
     */
    function module_path(string $module, string $path = ''): string
    {
        return dirname(__DIR__) . '/' . mb_ltrim($path, '/');
    }
}

if (! function_exists('fake')) {
    /**
     * Standalone stub: returns a Faker instance so factories can run without full Laravel app.
     */
    function fake(?string $locale = null): Faker\Generator
    {
        return Faker\Factory::create($locale ?? config('app.faker_locale', 'en_US'));
    }
}

// So that factory classes (in namespace Modules\Core\Database\Factories) resolve fake() to global
if (! function_exists('Modules\Core\Database\Factories\fake')) {
    require_once __DIR__ . '/Fixtures/fake_factory_helper.php';
}

return $container;
