<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Str;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Console\Concerns\HasBenchmark;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Overrides\ModuleDocGenerator;
use Mtrajano\LaravelSwagger\FormatterManager;
use Mtrajano\LaravelSwagger\GenerateSwaggerDoc as BaseGenerateSwaggerDoc;
use Mtrajano\LaravelSwagger\LaravelSwaggerException;
use Nwidart\Modules\Facades\Module;
use Override;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;

final class SwaggerGenerateCommand extends BaseGenerateSwaggerDoc
{
    use HasBenchmark;

    public function __construct()
    {
        $this->signature .= '
                {--m|module= : Filter to a specific Module}
        ';
        $this->description .= ' <fg=green>(⚡ Modules\Core)</fg=green>';

        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(): int
    {
        $module_filter = $this->option('module');

        foreach (modules(true, false, false) as $module_name) {
            // @codeCoverageIgnoreStart
            if ($module_name !== 'App' && ! class_exists(Module::class)) {
                continue;
            }
            // @codeCoverageIgnoreEnd

            if ($module_filter && $module_name !== $module_filter) {
                continue;
            }

            $this->moduleHandle($module_name);
        }

        resolve(ICacheLabelIndex::class)->flush(CacheLabel::docs());

        return Command::SUCCESS;
    }

    /**
     * @throws InvalidArgumentException
     * @throws BindingResolutionException
     * @throws LaravelSwaggerException
     */
    public function moduleHandle(string $moduleName): void
    {
        $filter = $this->option('filter') ?: null;

        /** @var string|null $file */
        $file = $this->option('output') ?: swagger_doc_path($moduleName);
        $config = $this->resolveSwaggerConfig();

        if ($file && app()->environment('testing') && $this->isCommittedSwaggerPath($file)) {
            throw new LaravelSwaggerException(
                'Refusing to overwrite committed swagger assets during tests. Pass --output to a temporary path.',
            );
        }

        if ($moduleName !== 'App') {
            $module_path = Module::getModulePath($moduleName);
            $module_json = $this->readModuleJson($module_path);
            $title = $config['title'] ?? '';
            $config['title'] = (is_string($title) ? $title : '') . ' ' . $module_json['name'] . ' module';
            $keywords = $module_json['keywords'];
            $config['description'] = $module_json['description'] . ($keywords === [] ? '' : ' (' . implode(', ', $keywords) . ')');
            $composer_version = $this->readComposerVersion($module_path . 'composer.json');

            if ($composer_version !== null) {
                $config['appVersion'] = $composer_version;
            }
        }

        $module_namespace = config('modules.namespace');
        $namespace = is_string($module_namespace) && $module_namespace !== '' ? $module_namespace : 'Modules';

        $doc = new ModuleDocGenerator($config, $moduleName !== 'App' ? $namespace . '\\' . $moduleName : $moduleName, $filter)->generate();
        $doc['tags'] = [$moduleName];

        // @codeCoverageIgnoreStart
        // OpenAPI path keys use Laravel route URIs (e.g. api/v1/...), which do not contain the literal "/api/" substring.
        if (array_filter($doc['paths'], static fn (string $k): bool => Str::contains($k, '/api/'), ARRAY_FILTER_USE_KEY) !== []) {
            $doc['tags'][] = 'Api';
        }
        // @codeCoverageIgnoreEnd

        $formattedDoc = new FormatterManager($doc)
            ->setFormat($this->option('format'))
            ->format();

        if ($file) {
            $folder = Str::beforeLast($file, DIRECTORY_SEPARATOR);

            if (! file_exists($folder)) {
                mkdir($folder, recursive: true);
            }

            $old_doc = null;

            if (file_exists($file)) {
                $old_doc_contents = file_get_contents($file);

                if ($old_doc_contents !== false) {
                    if ($this->option('format') === 'json') {
                        $decoded = json_decode($old_doc_contents, true);
                        $old_doc = is_array($decoded) ? $this->normalizeConfigArray($decoded) : null;
                    } elseif ($this->option('format') === 'yaml') {
                        $parsed_old = Yaml::parse($old_doc_contents, Yaml::PARSE_OBJECT_FOR_MAP);
                        $encoded = json_encode($parsed_old, JSON_THROW_ON_ERROR);
                        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
                        $old_doc = is_array($decoded) ? $this->normalizeConfigArray($decoded) : null;
                    }
                }
            }

            file_put_contents($file, $formattedDoc);

            $this->verboseGeneration($doc, $old_doc);
        } else {
            $this->line($formattedDoc); // @codeCoverageIgnore
        }
    }

    #[Override]
    protected function getOptions(): array
    {
        return [
            ['module', 'm', InputOption::VALUE_OPTIONAL, 'Filter to a specific Module'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSwaggerConfig(): array
    {
        $config = config('laravel-swagger');

        if (is_array($config) && $config !== []) {
            return $this->normalizeConfigArray($config);
        }

        $config_path = module_path('Core', 'config/laravel-swagger.php');

        if (! is_file($config_path)) {
            throw new LaravelSwaggerException('laravel-swagger configuration is missing.');
        }

        $loaded = require $config_path;

        if (! is_array($loaded)) {
            throw new LaravelSwaggerException('laravel-swagger configuration is missing.');
        }

        return $this->normalizeConfigArray($loaded);
    }

    /**
     * @param  array<mixed, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeConfigArray(array $config): array
    {
        $normalized = [];

        foreach ($config as $key => $value) {
            if (! is_string($key)) {
                throw new LaravelSwaggerException('laravel-swagger configuration keys must be strings.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @return array{name: string, description: string, keywords: list<string>}
     */
    private function readModuleJson(string $modulePath): array
    {
        $contents = file_get_contents($modulePath . DIRECTORY_SEPARATOR . 'module.json');

        if ($contents === false) {
            throw new LaravelSwaggerException('Could not read module.json for ' . $modulePath);
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new LaravelSwaggerException('Invalid module.json for ' . $modulePath);
        }

        $name = $decoded['name'] ?? null;
        $description = $decoded['description'] ?? null;
        $keywords = $decoded['keywords'] ?? [];

        if (! is_string($name) || ! is_string($description) || ! is_array($keywords)) {
            throw new LaravelSwaggerException('Invalid module.json for ' . $modulePath);
        }

        $keyword_list = [];

        foreach ($keywords as $keyword) {
            if (! is_string($keyword)) {
                throw new LaravelSwaggerException('Invalid module.json keywords for ' . $modulePath);
            }

            $keyword_list[] = $keyword;
        }

        return [
            'name' => $name,
            'description' => $description,
            'keywords' => $keyword_list,
        ];
    }

    private function readComposerVersion(string $composerPath): ?string
    {
        $contents = file_get_contents($composerPath);

        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents);

        if (! is_object($decoded) || ! isset($decoded->version) || ! is_string($decoded->version)) {
            return null;
        }

        return $decoded->version;
    }

    private function isCommittedSwaggerPath(string $file): bool
    {
        foreach (modules(true, false, false) as $module_name) {
            if ($file === swagger_doc_path($module_name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>|null  $old_doc
     */
    private function verboseGeneration(array $doc, ?array $old_doc): void
    {
        $info = $doc['info'] ?? null;
        $title = is_array($info) && isset($info['title']) && is_string($info['title']) ? $info['title'] : 'Swagger documentation';
        $this->info($title);

        $paths = $doc['paths'] ?? null;

        if (! is_array($paths)) {
            $this->line('');

            return;
        }

        $old_paths = is_array($old_doc) && isset($old_doc['paths']) && is_array($old_doc['paths']) ? $old_doc['paths'] : [];

        foreach ($paths as $path => $methods) {
            if (! is_array($methods)) {
                continue;
            }

            $keys = array_keys($methods);
            $imploded_methods = implode('|', array_map(
                static fn (int|string $method): string => mb_strtoupper((string) $method),
                $keys,
            ));
            $post_methods_padding = max(0, 40 - mb_strlen($imploded_methods));
            $path_string = is_string($path) ? $path : (string) $path;
            $post_route_padding = max(0, 80 - mb_strlen($path_string));

            if (isset($old_paths[$path]) && $old_paths[$path] === $methods) {
                $color = 'gray';
                $message = 'unchanged';
            } elseif (isset($old_paths[$path])) {
                $color = 'yellow';
                $message = 'updated';
            } else {
                $color = 'green';
                $message = 'new';
            }

            $this->line($imploded_methods . str_repeat(' ', $post_methods_padding) . $path_string . str_repeat(' ', $post_route_padding) . sprintf('<fg=%s>%s</fg=%s>', $color, $message, $color));
        }

        $this->line('');
    }
}
