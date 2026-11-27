<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\Core\Helpers\HelpersCache;
use Modules\Core\Models\User as CoreUser;
use Nwidart\Modules\Module;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

if (! function_exists('class_module')) {
    /**
     * Get the module of a class.
     *
     * @param  class-string  $class  The class to get the module of
     */
    function class_module(string|object $class): string
    {
        $class = is_string($class) ? $class : $class::class;
        $exploded = explode('\\', $class);

        if (head($exploded) === 'Modules' && count($exploded) > 1) {
            $module_class = Nwidart\Modules\Facades\Module::class;

            if ($module_class::has($exploded[1])) {
                return $exploded[1];
            }
        }

        return 'App';
    }
}

if (! function_exists('file_module')) {
    /**
     * Get the module of a file.
     *
     * @param  string  $file  The file to get the module of
     */
    function file_module(string $file): string
    {
        if (! Str::contains($file, 'Modules')) {
            return 'App';
        }

        return Str::before(Str::after($file, 'Modules' . DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
    }
}

if (! function_exists('modules')) {
    /**
     * get list of available modules.
     *
     * @param  bool  $showMainApp  add main app into modules list
     * @param  bool  $fullpath  return only module name or full path on file system
     * @param  bool  $onlyActive  return only active modules
     * @param  string|null  $onlyModule  filter for specified module
     * @param  bool|null  $prioritySort  sort modules by priority
     * @param  callable|null  $filter  filter for specified modules
     * @return array<int,string>
     */
    function modules(bool $showMainApp = false, bool $fullpath = false, bool $onlyActive = true, ?string $onlyModule = null, ?bool $prioritySort = false, ?callable $filter = null): array
    {
        $module_class = Nwidart\Modules\Facades\Module::class;
        $modules = [];

        if (class_exists($module_class)) {
            try {
                $modules = $onlyActive ? $module_class::allEnabled() : $module_class::all();
            } catch (Throwable) {
                // If the modules system is not bootstrapped (for example in some
                // isolated unit tests), gracefully fall back to an empty list.
                $modules = [];
            }
        }
        $remapped_modules = [];

        foreach ($modules as $class) {
            $module_name = $class->getName();

            if ($filter !== null && ! $filter($module_name)) {
                continue;
            }

            $remapped_modules[$module_name] = $class;
        }

        if (! in_array($onlyModule, [null, '', '0'], true)) {
            $onlyModule = ucfirst($onlyModule);
            $remapped_modules = array_filter($remapped_modules, fn (string $k): bool => $k === $onlyModule, ARRAY_FILTER_USE_KEY);
        }

        if ($prioritySort === true) {
            uasort($remapped_modules, static fn (Module $a, Module $b): int => $a->getPriority() <=> $b->getPriority());
        }

        $remapped_modules = $fullpath ? array_map(fn (Module $m): string => $m->getPath(), $remapped_modules) : array_keys($remapped_modules);

        if ($showMainApp && (in_array($onlyModule, [null, '', '0', 'App'], true))) {
            if ($fullpath) {
                $app_path = null;

                try {
                    $app_instance = app();

                    if (is_object($app_instance) && method_exists($app_instance, 'path')) {
                        /** @var string $resolved */
                        $resolved = $app_instance->path();
                        $app_path = $resolved;
                    }
                } catch (Throwable) {
                    $app_path = null;
                }

                // Fallback for minimal or non-Laravel environments (e.g. isolated tests)
                $remapped_modules['App'] = $app_path ?? dirname(__DIR__, 2);
            } else {
                array_unshift($remapped_modules, 'App');
            }

            ksort($remapped_modules);
        }

        return $remapped_modules;
    }
}

if (! function_exists('is_laraplate_owned_module')) {
    /**
     * Whether a module is an official Laraplate-owned distribution package.
     *
     * Resolution: App is never owned; module.json `laraplate_owned` when present;
     * otherwise composer.json `name` matching `getlaraplate/lara*`.
     */
    function is_laraplate_owned_module(string $name): bool
    {
        if ($name === 'App' || strcasecmp($name, 'App') === 0) {
            return false;
        }

        $module_name = str($name)->studly()->toString();

        try {
            $path = module_path($module_name);
        } catch (Throwable) {
            return false;
        }

        if ($path === '' || ! is_dir($path)) {
            return false;
        }

        return laraplate_module_is_owned_at_path($path);
    }
}

if (! function_exists('laraplate_module_is_owned_at_path')) {
    /**
     * Ownership resolution from a module root directory (module.json + composer.json).
     */
    function laraplate_module_is_owned_at_path(string $path): bool
    {
        $module_json_path = $path . DIRECTORY_SEPARATOR . 'module.json';

        if (is_file($module_json_path)) {
            $decoded = json_decode((string) file_get_contents($module_json_path), true);

            if (is_array($decoded) && array_key_exists('laraplate_owned', $decoded)) {
                return (bool) $decoded['laraplate_owned'];
            }
        }

        $composer_path = $path . DIRECTORY_SEPARATOR . 'composer.json';

        if (! is_file($composer_path)) {
            return false;
        }

        $composer = json_decode((string) file_get_contents($composer_path), true);
        $package = is_array($composer) ? ($composer['name'] ?? null) : null;

        return is_string($package) && str_starts_with($package, 'getlaraplate/lara');
    }
}

if (! function_exists('normalize_path')) {
    /**
     * get list of available translations.
     *
     * @param  string  $p  return path with correct directory separator
     */
    function normalize_path(string $p): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p);
    }
}

if (! function_exists('swagger_doc_path')) {
    /**
     * Resolve the committed OpenAPI document path for a module or the main app shell.
     */
    function swagger_doc_path(string $moduleName): string
    {
        $filename = $moduleName . '-swagger.json';

        if ($moduleName === 'App') {
            return resource_path('swagger' . DIRECTORY_SEPARATOR . $filename);
        }

        return module_path($moduleName, 'resources/swagger/' . $filename);
    }
}

if (! function_exists('connections')) {
    /**
     * Get list of connections from models. Results are memoized per $onlyActive key.
     *
     * @param  bool  $onlyActive  filter for only active modules
     * @return array<string>
     */
    function connections(bool $onlyActive = true): array
    {
        $cache_key = $onlyActive ? 'active' : 'all';
        $cached = HelpersCache::getConnections($cache_key);

        if ($cached !== null) {
            return $cached;
        }

        $connections = [];

        if (! $onlyActive) {
            $db_connections = config('database.connections', []);

            if (! is_array($db_connections)) {
                $db_connections = [];
            }

            foreach ($db_connections as $connection) {
                if (! is_object($connection) || ! method_exists($connection, 'getDriverName')) {
                    continue;
                }

                $driver = $connection->getDriverName();

                if (! is_string($driver) || in_array($driver, $connections, true)) {
                    continue;
                }

                $connections[] = $driver;
            }

            HelpersCache::setConnections($cache_key, $connections);

            return $connections;
        }

        foreach (models($onlyActive) as $model) {
            $connection = new $model()->getConnection();
            $driver = $connection->getDriverName();

            if (! in_array($driver, $connections, true)) {
                $connections[] = $driver;
            }
        }

        HelpersCache::setConnections($cache_key, $connections);

        return $connections;
    }
}

if (! function_exists('translations')) {
    /**
     * get list of available translations.
     *
     * @param  bool  $fullpath  return only unique translation code or full all paths on file system
     * @param  bool  $onlyActive  filter for only active modules
     * @return array<string>
     */
    function translations(bool $fullpath = false, bool $onlyActive = true): array
    {
        $app_dir = base_path('lang');
        $app_languages = glob($app_dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        $configured_lang_path = config('modules.paths.generator.lang.path');
        $langs_subpath = is_string($configured_lang_path) ? $configured_lang_path : '';

        $modules_languages = $fullpath
            ? $app_languages
            : array_map(fn (string $l): string => str_replace($app_dir . DIRECTORY_SEPARATOR, '', $l), $app_languages);

        foreach (modules(false, true, $onlyActive) as $module) {
            $is_app = (bool) preg_match("/[\\\\\/]app$/", $module);
            $path = $module . DIRECTORY_SEPARATOR . ($is_app ? 'lang' : $langs_subpath) . DIRECTORY_SEPARATOR;
            $files = glob($path . '*', GLOB_ONLYDIR) ?: [];

            foreach ($files as $file) {
                if (! $fullpath) {
                    $exploded = explode(DIRECTORY_SEPARATOR, $file);
                    $language = array_pop($exploded);

                    if (! in_array($language, $modules_languages, true)) {
                        $modules_languages[] = $language;
                    }
                } else {
                    $modules_languages[] = $file;
                }
            }
        }

        sort($modules_languages);

        return $modules_languages;
    }
}

if (! function_exists('migrations')) {
    /**
     * check if there are pending migrations.
     *
     * @param  bool  $count  return only count or full list
     * @param  bool  $onlyPending  return only pending migrations
     * @param  bool  $onlyActive  filter for only active modules
     * @param  string|null  $onlyModule  filter for specified module
     * @return false|int|array<string> number if count requested, string[] if list requested, false if error occured
     */
    function migrations(bool $count = false, bool $onlyPending = false, bool $onlyActive = true, ?string $onlyModule = null): array|int|false
    {
        try {
            $found = [];

            foreach (modules(true, false, $onlyActive, $onlyModule) as $m) {
                $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

                if ($m === 'App') {
                    Artisan::call('migrate:status', outputBuffer: $output);
                } else {
                    Artisan::call('module:migrate-status', ['module' => $m], $output);
                }

                $content = explode(PHP_EOL, $output->fetch());

                foreach ($content as $line) {
                    $trimmed = mb_trim($line);

                    if (preg_match("/^\d{4}_\d{2}_\d{2}_\d{6}_/", $trimmed) === 1 && (preg_match('/Ran$/', $trimmed) === 0 || ! $onlyPending)) {
                        $found[] = $line;
                    }
                }
            }

            return $count ? count($found) : $found;
        } catch (Exception) {
            return false;
        }
    }
}

if (! function_exists('models')) {
    /**
     * List all Models. Results are memoized per $onlyActive key.
     * Module and custom filters are applied in-memory from the cached full list.
     *
     * @param  bool  $onlyActive  filter for only active modules
     * @param  string|null  $onlyModule  filter for specified module
     * @param  callable|null  $filter  filter for specified models
     * @return list<class-string<Model>>
     */
    function models(bool $onlyActive = true, ?string $onlyModule = null, ?callable $filter = null): array
    {
        $cache_key = $onlyActive ? 'active' : 'all';
        $cached = HelpersCache::rememberModels($cache_key, static function () use ($onlyActive): array {
            $discovered = [];
            $all_modules = modules(true, true, $onlyActive);

            foreach ($all_modules as $m) {
                $is_app = (bool) preg_match("/[\\\\\/]app$/", $m);
                $configured_model_path = config('modules.paths.generator.model.path');
                $modules_models_folder = is_string($configured_model_path) ? $configured_model_path : '';
                $models_path = $m . DIRECTORY_SEPARATOR . ($is_app ? 'Models' : $modules_models_folder);

                // In minimal or non-Laravel environments the filesystem component may not be bound.
                // In that case we gracefully skip automatic model discovery.
                if (! app()->bound('files')) {
                    continue;
                }

                if (! is_dir($models_path)) {
                    continue;
                }

                $model_files = File::allFiles($models_path);

                if ($model_files === []) {
                    continue;
                }

                if ($is_app) {
                    $namespace = 'App\\Models\\';
                } else {
                    $module_name = basename($m);
                    $configured_namespace = config('modules.namespace');
                    $namespace = sprintf(
                        '%s\\%s\\%s\\',
                        is_string($configured_namespace) ? $configured_namespace : 'Modules',
                        $module_name,
                        Str::replace(['app/', '/'], ['', '\\'], $modules_models_folder),
                    );
                }

                foreach ($model_files as $model_file) {
                    if ($model_file->getExtension() !== 'php') {
                        continue;
                    }

                    $class_subnamespace = $namespace . preg_replace(['/\.' . $model_file->getExtension() . '/', '/\//'], ['', '\\'], $model_file->getRelativePathName());

                    if (! is_subclass_of($class_subnamespace, Model::class)) {
                        continue;
                    }

                    if (new ReflectionClass($class_subnamespace)->isAbstract()) {
                        continue;
                    }

                    $discovered[] = $class_subnamespace;
                }
            }

            return $discovered;
        });

        $result = $cached;

        if (! in_array($onlyModule, [null, '', '0'], true)) {
            $onlyModule = ucfirst($onlyModule);
            $result = array_values(array_filter($result, static function (string $class) use ($onlyModule): bool {
                if ($onlyModule === 'App') {
                    return str_starts_with($class, 'App\\');
                }

                return str_starts_with($class, 'Modules\\' . $onlyModule . '\\');
            }));
        }

        if ($filter !== null) {
            return array_values(array_filter($result, $filter));
        }

        return $result;
    }
}

if (! function_exists('controllers')) {
    /**
     * list all Controllers.
     *
     * @param  bool  $onlyActive  filter for only active modules
     * @param  string|null  $onlyModule  filter for specified module
     * @return array<int,string>
     *
     * @psalm-return list{0?: string,...}
     */
    function controllers(bool $onlyActive = true, ?string $onlyModule = null): array
    {
        $configured_controller_path = config('modules.paths.generator.controller.path');
        $modules_controllers_folder = is_string($configured_controller_path) ? $configured_controller_path : '';
        $modules = modules(true, true, $onlyActive, $onlyModule);
        $controllers = [];

        foreach ($modules as $m) {
            $is_app = (bool) preg_match("/[\\\\\/]app$/", $m);

            $controllers_path = $m . DIRECTORY_SEPARATOR . ($is_app ? 'Http' . DIRECTORY_SEPARATOR . 'Controllers' : $modules_controllers_folder);

            if (! is_dir($controllers_path)) {
                continue;
            }

            $controllers_files = File::allFiles($controllers_path);

            if ($is_app) {
                $namespace = 'App\\Http\\Controllers\\';
            } else {
                $module_name = basename($m);
                $configured_namespace = config('modules.namespace');
                $namespace = sprintf(
                    '%s\\%s\\%s\\',
                    is_string($configured_namespace) ? $configured_namespace : 'Modules',
                    $module_name,
                    str_replace(DIRECTORY_SEPARATOR, '\\', $modules_controllers_folder),
                );
            }

            foreach ($controllers_files as $controller_file) {
                $name = $controller_file->getFilenameWithoutExtension();

                if ($name !== 'Controller' && ! Str::contains($name, 'Abstract')) {
                    $controllers[] = $namespace . $name;
                }
            }
        }

        return $controllers;
    }
}

if (! function_exists('routes')) {
    /**
     * list all Controllers.
     *
     * @param  bool  $onlyActive  filter for only active modules
     * @param  string|null  $onlyModule  filter for specified module
     * @return list<Route>
     */
    function routes(bool $onlyActive = true, ?string $onlyModule = null): array
    {
        /** @var array<int,Route> $routes */
        $routes = [];
        $modules = modules(true, false, $onlyActive, $onlyModule);
        $all_routes = resolve(Router::class)->getRoutes()->getRoutes();
        usort($all_routes, static fn (Route $a, Route $b): int => $a->uri() <=> $b->uri());

        foreach ($all_routes as $route) {
            $reference = $route->action['namespace'] ?? $route->action['controller'] ?? $route->action['uses'];

            if ($reference instanceof Closure) {
                $reference = (new ReflectionFunction($reference))->getName();
            } elseif (is_string($reference) && is_callable($reference)) {
                $reference = (new ReflectionFunction($reference))->getName();
            } elseif (is_array($reference) && isset($reference[0], $reference[1])) {
                $class = is_object($reference[0]) ? $reference[0]::class : (string) $reference[0];
                $reference = $class . '::' . (string) $reference[1];
            }

            $exploded = explode('\\', (string) $reference);

            if (
                ($exploded[0] !== 'Modules' && (in_array($onlyModule, [null, '', '0', 'App'], true)))
                || (isset($exploded[1]) && in_array($exploded[1], $modules, true) && (in_array($onlyModule, [null, '', '0'], true) || $exploded[1] === $onlyModule))) {
                $routes[] = $route;
            }
        }

        return array_values($routes);
    }
}

if (! function_exists('version')) {
    /**
     * Return App Version.
     */
    function version(): string
    {
        $json = file_get_contents(base_path('composer.json'));

        if ($json === false) {
            return '';
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return '';
        }

        $version = $decoded['version'] ?? '';

        return is_string($version) ? $version : '';
    }
}

if (! function_exists('api_versions')) {
    /**
     * Return Api Versions.
     *
     * @return array<int,string>
     *
     * @psalm-return list<string>
     */
    function api_versions(): array
    {
        $routes = resolve(Router::class)->getRoutes()->getRoutes();
        $versions = [];

        foreach ($routes as $route) {
            $uri = $route->uri;
            $matches = [];
            preg_match("/^api\/(v\d+)\//", (string) $uri, $matches);

            if (count($matches) === 2 && ! in_array($matches[1], $versions, true)) {
                $versions[] = $matches[1];
            }
        }

        return $versions;
    }
}

if (! function_exists('preview')) {
    /**
     * Getter/Setter for the approvals preview flag.
     *
     * Storage is chosen by {@see Modules\Core\Http\Middleware\PreviewMiddleware}:
     * - `request` (app / api): the flag lives on the current request only; a missing
     *   `preview` query/body param is off, and the session is never read or written.
     * - `session` (admin): the flag persists across requests until explicitly cleared.
     *
     * Outside an HTTP request (console, bare unit tests) the session is the store,
     * which keeps the existing helper tests and Artisan tooling working.
     *
     * @param  bool|null  $enablePreview  enable preview flag
     */
    function preview(?bool $enablePreview = null): bool
    {
        $request = request();
        $storage = $request->attributes->get('_preview_storage', 'session');

        if ($enablePreview !== null) {
            if ($storage === 'session') {
                session()->put('preview', $enablePreview);
            }

            $request->attributes->set('preview', $enablePreview);

            return $enablePreview;
        }

        if ($request->attributes->has('preview')) {
            return (bool) $request->attributes->get('preview');
        }

        return (bool) session('preview', false);
    }
}

if (! function_exists('class_uses_trait')) {
    /**
     * Check if a class uses a trait.
     *
     * @param  string|object  $class  The class to check
     * @param  string  $uses  The trait to check for
     * @param  bool  $recursive  Check if the trait is used in the class or its parents
     */
    function class_uses_trait(string|object $class, string $uses, bool $recursive = true): bool
    {
        $class = is_string($class) ? $class : $class::class;
        $traits = $recursive ? class_uses_recursive($class) : class_uses($class);

        if (! is_array($traits)) {
            $traits = [];
        }

        return in_array($uses, $traits, true);
    }
}

if (! function_exists('is_json')) {
    /**
     * Check if a string is a valid JSON.
     *
     * @param  string  $string  The string to check
     */
    function is_json(string $string): bool
    {
        /** @psalm-suppress UnusedFunctionCall */
        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }
}

if (! function_exists('array_sort_keys')) {
    /**
     * Sort the keys of an array.
     *
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    function array_sort_keys(array $array): array
    {
        $keys = array_keys($array);
        sort($keys);
        $result = [];

        foreach ($keys as $key) {
            $value = $array[(string) $key];

            if (is_array($value)) {
                $nested_array = [];

                foreach ($value as $nested_key => $nested_value) {
                    $nested_array[(string) $nested_key] = $nested_value;
                }

                $result[(string) $key] = array_sort_keys($nested_array);
            } else {
                $result[(string) $key] = $value;
            }
        }

        return $result;
    }
}

if (! function_exists('user_class')) {
    /**
     * Get the user model class.
     *
     * The configured model must be Core's User or extend it: Core, and every module through it, relies on what
     * Core's User adds to Laravel's.
     *
     * @return class-string<CoreUser>
     */
    function user_class(): string
    {
        $model = config('auth.providers.users.model');

        if (! is_string($model)) {
            throw new InvalidArgumentException('User model class must be a string.');
        }

        if (! is_a($model, CoreUser::class, true)) {
            throw new InvalidArgumentException('Configured user model must extend ' . CoreUser::class);
        }

        return $model;
    }
}

if (! function_exists('cast_value')) {
    /**
     * Cast a value to a specific type.
     *
     * @param  mixed  $value  The value to cast
     * @param  string|null  $type  The type to cast to
     */
    function cast_value(mixed $value, ?string $type = null): mixed
    {
        if (! in_array($type, [null, '', '0'], true)) {
            return match (mb_strtolower($type)) {
                'int', 'integer' => match (true) {
                    is_int($value) => $value,
                    is_float($value) => (int) $value,
                    is_string($value) && is_numeric($value) => (int) $value,
                    is_bool($value) => (int) $value,
                    default => 0,
                },
                'float', 'double', 'real' => match (true) {
                    is_float($value) => $value,
                    is_int($value) => (float) $value,
                    is_string($value) && is_numeric($value) => (float) $value,
                    is_bool($value) => (float) (int) $value,
                    default => 0.0,
                },
                'string' => match (true) {
                    is_string($value) => $value,
                    is_scalar($value) => (string) $value,
                    default => '',
                },
                'bool', 'boolean' => (bool) $value,
                'array' => is_array($value) ? $value : [],
                'object' => is_object($value) ? $value : (object) [],
                'null' => null,
                default => throw new InvalidArgumentException('Unsupported type: ' . $type),
            };
        }

        if ($value === null || $value === 'null') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            if (is_int($value)) {
                return $value;
            }

            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            if (filter_var($value, FILTER_VALIDATE_INT) !== false) {
                return (int) $value;
            }

            return (float) $value;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return '';
        }

        if (mb_strtolower($value) === 'true') {
            return true;
        }

        if (mb_strtolower($value) === 'false') {
            return false;
        }

        if ((mb_substr($value, 0, 1) === '[' && mb_substr($value, -1) === ']')
            || (mb_substr($value, 0, 1) === '{' && mb_substr($value, -1) === '}')
        ) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }
}

if (! function_exists('class_module')) {
    /**
     * Get the module of a class.
     *
     * @param  string  $class  The class to get the module of
     */
    function class_module(string $class): ?string
    {
        if (! class_exists($class)) {
            return null;
        }

        return Str::startsWith($class, 'Modules\\') ? Str::before(Str::after($class, 'Modules\\'), '\\') : null;
    }
}
