<?php

declare(strict_types=1);

use Modules\Core\Overrides\ServiceProvider;

it('can be instantiated with app and has name properties', function (): void {
    $provider = new class(app()) extends ServiceProvider
    {
        public string $name = 'Test';

        public string $nameLower = 'test';

        public function __construct($app)
        {
            parent::__construct($app);
            $this->name = 'Test';
            $this->nameLower = 'test';
        }
    };

    expect($provider)->toBeInstanceOf(ServiceProvider::class);
    expect($provider->name)->toBe('Test');
    expect($provider->nameLower)->toBe('test');
});

it('registerConfig returns early when modules config path is not set', function (): void {
    $modules = config('modules', []);
    unset($modules['paths']['generator']['config']['path']);
    config(['modules' => $modules]);

    $provider = new class(app()) extends ServiceProvider
    {
        public string $name = 'Test';

        public string $nameLower = 'test';

        public function publicRegisterConfig(): void
        {
            $this->registerConfig();
        }
    };

    expect(fn () => $provider->publicRegisterConfig())->not->toThrow(Throwable::class);
});

it('registerConfig merges php files from the module config directory', function (): void {
    $provider = new class(app()) extends ServiceProvider
    {
        public string $name = 'Core';

        public string $nameLower = 'core';

        public function publicRegisterConfig(): void
        {
            $this->registerConfig();
        }
    };

    $provider->publicRegisterConfig();

    expect(config('core'))->toBeArray();
});

it('mergeConfigFrom merges nested arrays when configuration is not cached', function (): void {
    // The suite reuses a cached configuration between tests, so the precondition is stated here.
    app()->instance('config_loaded_from_cache', false);

    $provider = new class(app()) extends ServiceProvider
    {
        public string $name = 'Test';

        public string $nameLower = 'test';

        public function publicMergeConfigFrom(string $path, string $key): void
        {
            $this->mergeConfigFrom($path, $key);
        }
    };

    $config_path = sys_get_temp_dir() . '/lp-merge-config-' . uniqid() . '.php';
    file_put_contents($config_path, '<?php return ["nested" => ["child" => "new"]];');

    config()->set('test.merge', ['nested' => ['child' => 'old', 'keep' => true]]);

    try {
        $provider->publicMergeConfigFrom($config_path, 'test.merge');

        expect(config('test.merge.nested.child'))->toBe('new')
            ->and(config('test.merge.nested.keep'))->toBeTrue();
    } finally {
        @unlink($config_path);
    }
});
