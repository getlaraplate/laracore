<?php

declare(strict_types=1);

use Elastic\Elasticsearch\Client as ElasticsearchClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes as BaseSoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;
use Laravel\Scout\EngineManager;
use Modules\Core\Http\Controllers\DocsController;
use Modules\Core\Inspector\SchemaInspector;
use Modules\Core\Locking\Locked;
use Modules\Core\Models\CronJob;
use Modules\Core\Performance\BootTime;
use Modules\Core\Providers\CoreServiceProvider;
use Modules\Core\Providers\FortifyServiceProvider;
use Modules\Core\Providers\RouteServiceProvider;
use Modules\Core\Search\Engines\ElasticsearchEngine;
use Modules\Core\Search\Engines\TypesenseEngine;
use Modules\Core\SoftDeletes\SoftDeletes;
use Modules\Core\Tests\Stubs\UserForcedSuperRole;
use Typesense\Client as TypesenseClient;
use Wotz\SwaggerUi\Http\Controllers\OpenApiJsonController;

beforeEach(function (): void {
    $this->provider = new CoreServiceProvider(app());
});

it('registerAuths runs without throwing', function (): void {
    $this->provider->registerAuths();

    expect(true)->toBeTrue();
});

it('configureDates uses CarbonImmutable', function (): void {
    invokePrivate($this->provider, 'configureDates');

    expect(Date::getTestNow())->toBeNull();
    $instance = Date::now();
    expect($instance)->toBeInstanceOf(Carbon\CarbonImmutable::class);
});

it('boot configures password defaults', function (): void {
    config(['auth.providers.users.model' => UserForcedSuperRole::class]);
    $this->provider->boot();

    $rule = Password::default();

    expect($rule)->toBeInstanceOf(Password::class);
});

it('configureModels enables strict attribute checks outside production', function (): void {
    app()['config']->set('app.env', 'testing');

    invokePrivate($this->provider, 'configureModels');

    expect(Model::preventsSilentlyDiscardingAttributes())->toBeTrue()
        ->and(Model::preventsAccessingMissingAttributes())->toBeTrue();
});

it('registerSearchClients binds elasticsearch and typesense clients', function (): void {
    config([
        'elastic.client.default' => 'default',
        'elastic.client.connections.default' => ['hosts' => ['http://localhost:9200']],
        'scout.typesense.client-settings' => [
            'api_key' => 'xyz',
            'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
            'connection_timeout_seconds' => 2,
        ],
    ]);

    invokePrivate($this->provider, 'registerSearchClients');

    expect(app()->make(ElasticsearchClient::class))->toBeInstanceOf(ElasticsearchClient::class)
        ->and(app()->make(TypesenseClient::class))->toBeInstanceOf(TypesenseClient::class);
});

it('register wires docs binding and fortify provider', function (): void {
    config([
        'elastic.client.default' => 'default',
        'elastic.client.connections.default' => ['hosts' => ['http://localhost:9200']],
        'scout.typesense.client-settings' => [
            'api_key' => 'xyz',
            'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
            'connection_timeout_seconds' => 2,
        ],
    ]);
    app()['env'] = 'testing';

    $this->provider->register();

    expect(app()->bound(OpenApiJsonController::class))->toBeTrue()
        ->and(app()->make(OpenApiJsonController::class))->toBeInstanceOf(DocsController::class)
        ->and(app()->getProvider(FortifyServiceProvider::class))->not->toBeNull()
        ->and(app()->make(ElasticsearchClient::class))->toBeInstanceOf(ElasticsearchClient::class)
        ->and(app()->make(TypesenseClient::class))->toBeInstanceOf(TypesenseClient::class)
        ->and(app()->getProvider(RouteServiceProvider::class))->not->toBeNull();
});

it('registerSearchEngines registers custom engines and core aliases', function (): void {
    $manager = new class()
    {
        public array $resolved = [];

        public function extend(string $name, callable $callback): void
        {
            $this->resolved[$name] = $callback(app());
        }
    };

    app()->instance(EngineManager::class, $manager);
    app()->instance(ElasticsearchClient::class, new class() {});
    app()->instance(TypesenseClient::class, new class() {});
    app()->bind(ElasticsearchEngine::class, static fn () => new class() {});
    app()->bind(TypesenseEngine::class, static fn () => new class() {});
    config([
        'search.engines.elasticsearch' => ['index' => 'test'],
        'search.engines.typesense' => ['index' => 'test'],
        'scout.typesense.max_total_results' => 1234,
    ]);

    invokePrivate($this->provider, 'registerSearchEngines');

    expect(array_keys($manager->resolved))->toEqualCanonicalizing(['elasticsearch', 'typesense'])
        ->and(app()->bound(Locked::class))->toBeTrue()
        ->and(app()->bound('locked'))->toBeTrue()
        ->and(app()->bound(SchemaInspector::class))->toBeTrue()
        ->and(app()->getAlias(SoftDeletes::class))->toBe(BaseSoftDeletes::class);
});

it('registerSearchEngines registers ide helper provider in local env', function (): void {
    app()['env'] = 'local';

    $manager = new class()
    {
        public function extend(string $name, callable $callback): void
        {
            $callback(app());
        }
    };

    app()->instance(EngineManager::class, $manager);
    app()->instance(ElasticsearchClient::class, new class() {});
    app()->instance(TypesenseClient::class, new class() {});
    app()->bind(ElasticsearchEngine::class, static fn () => new class() {});
    app()->bind(TypesenseEngine::class, static fn () => new class() {});
    config([
        'search.engines.elasticsearch' => ['index' => 'test'],
        'search.engines.typesense' => ['index' => 'test'],
    ]);

    invokePrivate($this->provider, 'registerSearchEngines');

    expect(app()->getProvider(Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class))->not->toBeNull();
});

it('registerCommands discovers command folders without errors', function (): void {
    config(['modules.paths.generator.command.path' => 'Console']);

    invokePrivate($this->provider, 'registerCommands');

    expect(true)->toBeTrue();
});

it('loadCronJobsFromDatabase returns empty array when cron table is missing', function (): void {
    $result = invokePrivate($this->provider, 'loadCronJobsFromDatabase', ['table_that_does_not_exist']);

    expect($result)->toBeArray()->toBeEmpty();
});

it('loadCronJobsFromDatabase returns active cron rows when table exists', function (): void {
    $cron = CronJob::factory()->create([
        'command' => 'inspire',
        'schedule' => '* * * * *',
        'is_active' => true,
    ]);

    $result = invokePrivate($this->provider, 'loadCronJobsFromDatabase', [$cron->getTable()]);

    expect($result)->toBeArray()
        ->and($result[0]['command'])->toBe('inspire')
        ->and((string) $result[0]['schedule'])->toBe('* * * * *');
});

it('loadCronJobsFromDatabase ignores stale SchemaInspector when cron table was dropped', function (): void {
    $cron_table = (new ReflectionClass(CronJob::class))->newInstanceWithoutConstructor()->getTable();

    // Warm process-level memoization so hasTable stays true after DROP (Pest suite leak).
    expect(SchemaInspector::getInstance()->hasTable($cron_table))->toBeTrue();

    Schema::drop($cron_table);

    expect(SchemaInspector::getInstance()->hasTable($cron_table))->toBeTrue();

    $result = invokePrivate($this->provider, 'loadCronJobsFromDatabase', [$cron_table]);

    expect($result)->toBeArray()->toBeEmpty();
});

it('loadCronJobsFromDatabase returns empty array on exceptions', function (): void {
    $cron_table = (new ReflectionClass(CronJob::class))->newInstanceWithoutConstructor()->getTable();

    // Table may already be gone from the previous regression test in this file.
    if (Schema::hasTable($cron_table)) {
        Schema::drop($cron_table);
    }

    $result = invokePrivate($this->provider, 'loadCronJobsFromDatabase', ['users']);

    expect($result)->toBeArray()->toBeEmpty();
});

it('loadCronJobsFromDatabase returns the parameters and the flags of an active row', function (): void {
    $cron = CronJob::factory()->create([
        'command' => 'cron-probe:prune',
        'parameters' => ['--all', '--limit=10'],
        'on_one_server' => false,
        'without_overlapping' => true,
        'is_active' => true,
    ]);

    $result = invokePrivate($this->provider, 'loadCronJobsFromDatabase', [$cron->getTable()]);

    expect($result[0]['parameters'])->toBe(['--all', '--limit=10'])
        ->and($result[0]['on_one_server'])->toBeFalse()
        ->and($result[0]['without_overlapping'])->toBeTrue();
});

it('loadCronJobsFromDatabase reads parameters that were stored as a JSON string', function (): void {
    $cron = CronJob::factory()->create(['command' => 'cron-probe:prune', 'is_active' => true]);
    Illuminate\Support\Facades\DB::table($cron->getTable())->where('id', $cron->id)
        ->update(['parameters' => json_encode('["--limit=10"]')]);

    $result = invokePrivate($this->provider, 'loadCronJobsFromDatabase', [$cron->getTable()]);

    expect($result[0]['parameters'])->toBe(['--limit=10']);
});

it('registerCommandSchedules passes the parameters of a cron row to its command, escaped by Laravel', function (): void {
    CronJob::factory()->create([
        'command' => 'cron-probe:prune',
        'parameters' => ['--all', '--model=Modules\\AI\\Models\\ContextualSuggestion', '--limit=10'],
        'is_active' => true,
    ]);

    invokePrivate($this->provider, 'registerCommandSchedules');

    expect(scheduledCronProbe()->command)
        ->toContain('cron-probe:prune')
        ->toContain(' --all')
        ->toContain("--model='Modules\\AI\\Models\\ContextualSuggestion'")
        ->toContain('--limit=10');
});

it('registerCommandSchedules applies the one-server and no-overlap flags of a cron row', function (): void {
    CronJob::factory()->create([
        'command' => 'cron-probe:prune',
        'on_one_server' => false,
        'without_overlapping' => false,
        'is_active' => true,
    ]);

    invokePrivate($this->provider, 'registerCommandSchedules');

    expect(scheduledCronProbe()->onOneServer)->toBeFalse()
        ->and(scheduledCronProbe()->withoutOverlapping)->toBeFalse();
});

it('registerCommandSchedules runs a cron row on one server and without overlapping by default', function (): void {
    CronJob::factory()->create([
        'command' => 'cron-probe:prune',
        'is_active' => true,
    ]);

    invokePrivate($this->provider, 'registerCommandSchedules');

    expect(scheduledCronProbe()->onOneServer)->toBeTrue()
        ->and(scheduledCronProbe()->withoutOverlapping)->toBeTrue();
});

it('registerCommandSchedules does not schedule a row whose parameters would not be escaped', function (): void {
    $cron = CronJob::factory()->create(['command' => 'cron-probe:prune', 'is_active' => true]);
    Illuminate\Support\Facades\DB::table($cron->getTable())->where('id', $cron->id)
        ->update(['parameters' => json_encode(['--all; rm -rf /'])]);

    invokePrivate($this->provider, 'registerCommandSchedules');

    expect(scheduledCronProbes())->toBe([]);
});

it('normalizeCronJobs gives parameters and both flags to an entry cached before they existed', function (): void {
    $result = invokePrivate($this->provider, 'normalizeCronJobs', [[['command' => 'inspire', 'schedule' => '* * * * *']]]);

    expect($result[0]['parameters'])->toBe([])
        ->and($result[0]['on_one_server'])->toBeTrue()
        ->and($result[0]['without_overlapping'])->toBeTrue();
});

it('registerCommandSchedules reads cached cron jobs without database calls', function (): void {
    $cache_key = (new ReflectionClass(CronJob::class))->newInstanceWithoutConstructor()->getTable();
    Cache::put($cache_key, []);

    invokePrivate($this->provider, 'registerCommandSchedules');
    runLastBootedCallback();

    expect(true)->toBeTrue();
});

it('registerCommandSchedules uses tagged cache hit and schedules cached commands', function (): void {
    $cache_key = (new ReflectionClass(CronJob::class))->newInstanceWithoutConstructor()->getTable();
    $cached_crons = [['command' => 'inspire', 'schedule' => '* * * * *']];

    $tagged_cache = new class($cached_crons, $cache_key)
    {
        public function __construct(private array $crons, private string $key) {}

        public function has(string $key): bool
        {
            return $key === $this->key;
        }

        public function get(string $key): array
        {
            return $key === $this->key ? $this->crons : [];
        }

        public function put(string $key, array $value): void {}
    };

    $cache_store = new class()
    {
        public function supportsTags(): bool
        {
            return true;
        }

        public function getCacheTags(): array
        {
            return ['core'];
        }
    };

    Cache::shouldReceive('store')->atLeast()->once()->andReturn($cache_store);
    Cache::shouldReceive('tags')->with(['core'])->andReturn($tagged_cache);

    $schedule = app()->make(Illuminate\Console\Scheduling\Schedule::class);
    $initial_count = count($schedule->events());

    invokePrivate($this->provider, 'registerCommandSchedules');
    runLastBootedCallback();

    expect(count($schedule->events()))->toBeGreaterThan($initial_count);
});

it('registerCommandSchedules stores loaded crons into tagged cache on miss', function (): void {
    $cron = CronJob::factory()->create([
        'command' => 'inspire',
        'schedule' => '* * * * *',
        'is_active' => true,
    ]);
    $cache_key = $cron->getTable();
    $put_called = false;

    $tagged_cache = new class($cache_key, $put_called)
    {
        public function __construct(private string $key, public bool &$put_called) {}

        public function has(string $key): bool
        {
            return false;
        }

        public function get(string $key): array
        {
            return [];
        }

        public function put(string $key, array $value): void
        {
            if ($key === $this->key && $value !== []) {
                $this->put_called = true;
            }
        }
    };

    $cache_store = new class()
    {
        public function supportsTags(): bool
        {
            return true;
        }

        public function getCacheTags(): array
        {
            return ['core'];
        }
    };

    Cache::shouldReceive('store')->atLeast()->once()->andReturn($cache_store);
    Cache::shouldReceive('tags')->with(['core'])->andReturn($tagged_cache);

    invokePrivate($this->provider, 'registerCommandSchedules');
    runLastBootedCallback();

    expect($put_called)->toBeTrue();
});

it('registerCommandSchedules stores loaded crons into default cache when tags unsupported', function (): void {
    $cron = CronJob::factory()->create([
        'command' => 'inspire',
        'schedule' => '* * * * *',
        'is_active' => true,
    ]);
    $cache_key = $cron->getTable();
    $put_called = false;

    $cache_store = new class()
    {
        public function supportsTags(): bool
        {
            return false;
        }
    };

    Cache::shouldReceive('store')->atLeast()->once()->andReturn($cache_store);
    Cache::shouldReceive('has')->with($cache_key)->atLeast()->once()->andReturnFalse();
    Cache::shouldReceive('put')->withArgs(function (string $key, array $value) use ($cache_key, &$put_called): bool {
        $ok = $key === $cache_key && $value !== [];

        if ($ok) {
            $put_called = true;
        }

        return $ok;
    })->atLeast()->once();

    invokePrivate($this->provider, 'registerCommandSchedules');
    runLastBootedCallback();

    expect($put_called)->toBeTrue();
});

it('registerCommandSchedules reads crons from default cache when tags unsupported and key exists', function (): void {
    $cache_key = (new ReflectionClass(CronJob::class))->newInstanceWithoutConstructor()->getTable();
    $cached_crons = [['command' => 'inspire', 'schedule' => '* * * * *']];

    $cache_store = new class()
    {
        public function supportsTags(): bool
        {
            return false;
        }
    };

    Cache::shouldReceive('store')->atLeast()->once()->andReturn($cache_store);
    Cache::shouldReceive('has')->with($cache_key)->atLeast()->once()->andReturnTrue();
    Cache::shouldReceive('get')->with($cache_key)->atLeast()->once()->andReturn($cached_crons);

    $schedule = app()->make(Illuminate\Console\Scheduling\Schedule::class);
    $initial_count = count($schedule->events());

    invokePrivate($this->provider, 'registerCommandSchedules');
    runLastBootedCallback();

    expect(count($schedule->events()))->toBeGreaterThan($initial_count);
});

it('configureUrls forces https in production when enabled', function (): void {
    URL::shouldReceive('forceScheme')->once()->with('https');
    config(['core.force_https' => true]);
    $fake_app = new class()
    {
        public function isProduction(): bool
        {
            return true;
        }
    };
    $provider_reflection = new ReflectionClass($this->provider);
    $app_property = $provider_reflection->getProperty('app');
    $app_property->setAccessible(true);
    $app_property->setValue($this->provider, $fake_app);

    invokePrivate($this->provider, 'configureUrls');

    expect(true)->toBeTrue();
});

function invokePrivate(object $instance, string $method, array $args = []): mixed
{
    $ref = new ReflectionClass($instance);
    $m = $ref->getMethod($method);

    return $m->invokeArgs($instance, $args);
}

function runLastBootedCallback(): void
{
    $app_reflection = new ReflectionClass(app());
    $callbacks = $app_reflection->getProperty('bootedCallbacks');
    $callbacks->setAccessible(true);
    $callbacks_list = $callbacks->getValue(app());
    $callback = end($callbacks_list);

    if (! $callback instanceof Closure) {
        return;
    }

    $ref = new ReflectionFunction($callback);

    if ($ref->getNumberOfParameters() > 0) {
        $callback(app());
    } else {
        $callback();
    }
}

/**
 * @return list<Illuminate\Console\Scheduling\Event>
 */
function scheduledCronProbes(): array
{
    return array_values(array_filter(
        app()->make(Illuminate\Console\Scheduling\Schedule::class)->events(),
        static fn (Illuminate\Console\Scheduling\Event $event): bool => str_contains((string) $event->command, 'cron-probe:prune'),
    ));
}

function scheduledCronProbe(): Illuminate\Console\Scheduling\Event
{
    $events = scheduledCronProbes();

    expect($events)->toHaveCount(1);

    return $events[0];
}

it('records the instant the framework finished booting', function (): void {
    expect(app()->bound(BootTime::BOOTED_AT_BINDING))->toBeTrue()
        ->and(app(BootTime::BOOTED_AT_BINDING))->toBeFloat()->toBeLessThanOrEqual(microtime(true));
});
