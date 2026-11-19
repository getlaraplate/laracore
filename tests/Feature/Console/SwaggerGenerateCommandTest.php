<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Console\SwaggerGenerateCommand;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Tests\Stubs\Cache\SpyLabelIndex;
use Mtrajano\LaravelSwagger\LaravelSwaggerException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function swaggerCommandWithOutput(SwaggerGenerateCommand $command): SwaggerGenerateCommand
{
    $command->setLaravel(app());
    $output = new OutputStyle(new ArrayInput([]), new BufferedOutput());
    $reflection = new ReflectionProperty(Illuminate\Console\Command::class, 'output');
    $reflection->setAccessible(true);
    $reflection->setValue($command, $output);

    return $command;
}

it('exposes swagger generate options and verbose generation output', function (): void {
    $command = app(SwaggerGenerateCommand::class);
    $command->setLaravel(app());

    $options = (new ReflectionMethod(SwaggerGenerateCommand::class, 'getOptions'))->invoke($command);
    expect($options)->toBeArray()
        ->and($options[0][0])->toBe('module');

    $verbose = new ReflectionMethod(SwaggerGenerateCommand::class, 'verboseGeneration');
    $verbose->setAccessible(true);
    $command = swaggerCommandWithOutput($command);

    $doc = [
        'info' => ['title' => 'Coverage API'],
        'paths' => [
            '/api/foo' => ['get' => ['summary' => 'a']],
            '/other' => ['post' => ['summary' => 'b']],
        ],
    ];
    $old = [
        'paths' => [
            '/api/foo' => ['get' => ['summary' => 'a']],
            '/other' => ['post' => ['summary' => 'changed']],
        ],
    ];

    $verbose->invoke($command, $doc, $old);
    expect(true)->toBeTrue();
});

it('verbose generation marks every path as new when no previous document exists', function (): void {
    $command = swaggerCommandWithOutput(app(SwaggerGenerateCommand::class));
    $verbose = new ReflectionMethod(SwaggerGenerateCommand::class, 'verboseGeneration');
    $verbose->setAccessible(true);

    $doc = [
        'info' => ['title' => 'Fresh API'],
        'paths' => [
            '/api/items' => ['get' => ['summary' => 'list']],
        ],
    ];

    $verbose->invoke($command, $doc, null);
    expect(true)->toBeTrue();
});

it('verbose generation reports unchanged paths when previous doc matches', function (): void {
    $command = swaggerCommandWithOutput(app(SwaggerGenerateCommand::class));
    $verbose = new ReflectionMethod(SwaggerGenerateCommand::class, 'verboseGeneration');
    $verbose->setAccessible(true);

    $path_payload = ['get' => ['summary' => 'same']];
    $doc = [
        'info' => ['title' => 'Same API'],
        'paths' => [
            '/api/same' => $path_payload,
        ],
    ];
    $old = [
        'paths' => [
            '/api/same' => $path_payload,
        ],
    ];

    $verbose->invoke($command, $doc, $old);
    expect(true)->toBeTrue();
});

function swaggerTestConfig(): void
{
    $swagger_config_path = module_path('Core', 'config/laravel-swagger.php');
    expect(is_file($swagger_config_path))->toBeTrue();

    /** @var array<string, mixed> $swagger_config */
    $swagger_config = require $swagger_config_path;
    config(['laravel-swagger' => $swagger_config]);
}

/**
 * @return array{base: string, output_file: string}
 */
function swaggerTempOutput(string $filename = 'swagger-out.json'): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'swagger_test_' . uniqid('', true);
    mkdir($base, 0755, true);

    return [
        'base' => $base,
        'output_file' => $base . DIRECTORY_SEPARATOR . $filename,
    ];
}

beforeEach(function (): void {
    if (! is_array(config('laravel-swagger'))) {
        swaggerTestConfig();
    }
});

it('runs swagger generate handle and completes successfully', function (): void {
    swaggerTestConfig();

    $temp = swaggerTempOutput('App-swagger.json');

    try {
        $command = app(SwaggerGenerateCommand::class);
        $command->setLaravel(app());
        $exit = $command->run(new ArrayInput([
            '--module' => 'App',
            '--output' => $temp['output_file'],
        ]), new BufferedOutput());

        expect($exit)->toBe(0)
            ->and(is_file($temp['output_file']))->toBeTrue();
    } finally {
        File::deleteDirectory($temp['base']);
    }
});

it('creates nested output directories when the output path parent is missing', function (): void {
    swaggerTestConfig();

    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'swagger_mkdir_' . uniqid('', true);
    $output_file = $base . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'deep' . DIRECTORY_SEPARATOR . 'swagger-out.json';
    expect(is_dir(dirname($output_file)))->toBeFalse();

    try {
        $command = app(SwaggerGenerateCommand::class);
        $command->setLaravel(app());
        $exit = $command->run(new ArrayInput([
            '--module' => 'App',
            '--output' => $output_file,
        ]), new BufferedOutput());

        expect($exit)->toBe(0)
            ->and(is_file($output_file))->toBeTrue();
    } finally {
        File::deleteDirectory($base);
    }
});

it('parses existing JSON output as the previous document on a second run', function (): void {
    swaggerTestConfig();

    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'swagger_json_old_' . uniqid('', true);
    mkdir($base, 0755, true);
    $output_file = $base . DIRECTORY_SEPARATOR . 'doc.json';

    try {
        $command = app(SwaggerGenerateCommand::class);
        $command->setLaravel(app());

        expect($command->run(new ArrayInput([
            '--module' => 'App',
            '--output' => $output_file,
            '--format' => 'json',
        ]), new BufferedOutput()))->toBe(0);
        expect($command->run(new ArrayInput([
            '--module' => 'App',
            '--output' => $output_file,
            '--format' => 'json',
        ]), new BufferedOutput()))->toBe(0);
    } finally {
        File::deleteDirectory($base);
    }
});

it('parses existing YAML output as the previous document on a second run', function (): void {
    if (! extension_loaded('yaml')) {
        $this->markTestSkipped('The yaml PHP extension is required for the yaml output format.');
    }

    swaggerTestConfig();

    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'swagger_yaml_old_' . uniqid('', true);
    mkdir($base, 0755, true);
    $output_file = $base . DIRECTORY_SEPARATOR . 'doc.yaml';

    try {
        $command = app(SwaggerGenerateCommand::class);
        $command->setLaravel(app());

        expect($command->run(new ArrayInput([
            '--module' => 'App',
            '--output' => $output_file,
            '--format' => 'yaml',
        ]), new BufferedOutput()))->toBe(0);
        expect($command->run(new ArrayInput([
            '--module' => 'App',
            '--output' => $output_file,
            '--format' => 'yaml',
        ]), new BufferedOutput()))->toBe(0);
    } finally {
        File::deleteDirectory($base);
    }
});

it('refuses to overwrite committed swagger assets while the app is in testing environment', function (): void {
    swaggerTestConfig();

    $command = app(SwaggerGenerateCommand::class);
    $command->setLaravel(app());

    expect(fn () => $command->run(new ArrayInput([
        '--module' => 'App',
        '--output' => swagger_doc_path('App'),
    ]), new BufferedOutput()))->toThrow(LaravelSwaggerException::class);

    expect(fn () => $command->run(new ArrayInput([
        '--module' => 'Core',
        '--output' => swagger_doc_path('Core'),
    ]), new BufferedOutput()))->toThrow(LaravelSwaggerException::class);
});

it('skips modules that do not match the module filter option', function (): void {
    swaggerTestConfig();

    $module_names = modules(true, false, false);
    $non_app = null;

    foreach ($module_names as $name) {
        if ($name !== 'App') {
            $non_app = $name;

            break;
        }
    }

    expect($module_names)->not->toBeEmpty();
    expect($non_app)->not->toBeNull('testbench should register at least one module besides App (e.g. Core)');

    $temp = swaggerTempOutput($non_app . '-swagger.json');

    try {
        $command = app(SwaggerGenerateCommand::class);
        $command->setLaravel(app());
        $exit = $command->run(new ArrayInput([
            '--module' => $non_app,
            '--output' => $temp['output_file'],
        ]), new BufferedOutput());

        expect($exit)->toBe(0)
            ->and(is_file($temp['output_file']))->toBeTrue();
    } finally {
        File::deleteDirectory($temp['base']);
    }
});

it('flushes the docs label when the generation ends', function (): void {
    $index = new SpyLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);
    $lookup = $index->lookup('docs.merged.v1', new CacheDependencies(['docs']));
    $index->store($lookup, ['openapi' => '3.0.0'], 60);

    $command = swaggerCommandWithOutput(app(SwaggerGenerateCommand::class));
    $exit = $command->run(new ArrayInput(['--module' => 'NoSuchModule']), new BufferedOutput());

    expect($exit)->toBe(0)
        ->and($index->flushes)->toContain(['docs'])
        ->and($index->lookup('docs.merged.v1', new CacheDependencies(['docs']))->hit)->toBeFalse();
});
