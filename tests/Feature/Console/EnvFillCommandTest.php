<?php

declare(strict_types=1);

use Modules\Core\Console\EnvFillCommand;
use Modules\Core\Contracts\RefreshesConfigCache;
use Modules\Core\Tests\Stubs\RecordingConfigCacheRefresher;
use Symfony\Component\Console\Command\Command as BaseCommand;

beforeEach(function (): void {
    $this->realConfigCache = base_path('bootstrap/cache/config.php');
    $this->envDir = sys_get_temp_dir() . '/env-fill-test-' . bin2hex(random_bytes(6));
    mkdir($this->envDir, 0o700, true);

    $this->refresher = new RecordingConfigCacheRefresher();
    $this->app->instance(RefreshesConfigCache::class, $this->refresher);

    /** @var Illuminate\Contracts\Console\Kernel $kernel */
    $kernel = app(Illuminate\Contracts\Console\Kernel::class);

    if (! array_key_exists('env:fill', $kernel->all())) {
        $kernel->registerCommand(app(EnvFillCommand::class));
    }
});

afterEach(function (): void {
    expect(file_exists($this->realConfigCache))->toBeFalse();

    chmod($this->envDir, 0o700);

    exec('rm -rf ' . escapeshellarg($this->envDir));
});

/**
 * Writes an `.env` and an example into the test directory and returns the `--file` and `--example` options.
 *
 * @return array{--file: string, --example: string}
 */
function envFillFixture(string $directory, string $env, string $example): array
{
    file_put_contents($directory . '/.env', $env);
    chmod($directory . '/.env', 0o600);
    file_put_contents($directory . '/.env.example', $example);

    return ['--file' => $directory . '/.env', '--example' => $directory . '/.env.example'];
}

it('writes each answer at once and drops it from what is still missing', function (): void {
    $options = envFillFixture($this->envDir, "APP_ENV=production\n", "APP_ENV=local\nAI_TIMEOUT=30\n# AI_BATCH=32\nAI_REGION=eu\n");

    $this->artisan('env:fill', $options)
        ->expectsOutputToContain('3 variables missing')
        ->expectsQuestion('Variable to set', 'AI_TIMEOUT')
        ->expectsQuestion('AI_TIMEOUT', '45')
        ->expectsQuestion('Variable to set', 'AI_BATCH')
        ->expectsQuestion('AI_BATCH', '16')
        ->expectsQuestion('Variable to set', '')
        ->expectsOutputToContain('AI_TIMEOUT, AI_BATCH')
        ->assertExitCode(BaseCommand::SUCCESS);

    expect(file_get_contents($options['--file']))->toBe("APP_ENV=production\nAI_TIMEOUT=45\nAI_BATCH=16\n");
});

it('refuses a name that is not missing, lowercase or with a space, and asks again', function (): void {
    $options = envFillFixture($this->envDir, "APP_ENV=production\n", "APP_ENV=local\nAI_TIMEOUT=30\n");

    $this->artisan('env:fill', $options)
        ->expectsQuestion('Variable to set', 'APP_ENV')
        ->expectsOutputToContain('APP_ENV is not one of the missing variables')
        ->expectsQuestion('Variable to set', 'ai_timeout')
        ->expectsQuestion('Variable to set', 'AI TIMEOUT')
        ->expectsQuestion('Variable to set', 'AI_TIMEOUT')
        ->expectsQuestion('AI_TIMEOUT', '45')
        ->assertExitCode(BaseCommand::SUCCESS);

    expect(file_get_contents($options['--file']))->toBe("APP_ENV=production\nAI_TIMEOUT=45\n");
});

it('stops asking for a name once it is written and ends when nothing is left', function (): void {
    $options = envFillFixture($this->envDir, "APP_ENV=production\n", "APP_ENV=local\nONLY_ONE=1\n");

    $this->artisan('env:fill', $options)
        ->expectsQuestion('Variable to set', 'ONLY_ONE')
        ->expectsQuestion('ONLY_ONE', '2')
        ->assertExitCode(BaseCommand::SUCCESS);

    expect(file_get_contents($options['--file']))->toBe("APP_ENV=production\nONLY_ONE=2\n");
});

it('exits 0 with a message and no prompt when nothing is missing', function (): void {
    $options = envFillFixture($this->envDir, "APP_ENV=production\nAI_TIMEOUT=\n", "APP_ENV=local\nAI_TIMEOUT=30\n");

    $this->artisan('env:fill', $options)
        ->expectsOutputToContain('Nothing missing')
        ->assertExitCode(BaseCommand::SUCCESS);

    expect($this->refresher->calls)->toBe(0);
});

it('treats a commented line of the .env as missing', function (): void {
    $options = envFillFixture($this->envDir, "APP_ENV=production\n# AI_TIMEOUT=10\n", "APP_ENV=local\nAI_TIMEOUT=30\n");

    $this->artisan('env:fill', $options)
        ->expectsOutputToContain('1 variable missing')
        ->expectsQuestion('Variable to set', '')
        ->assertExitCode(BaseCommand::SUCCESS);
});

it('ends on an empty answer and asks no cache question when nothing was written', function (): void {
    $original = "APP_ENV=production\n";
    $options = envFillFixture($this->envDir, $original, "APP_ENV=local\nAI_TIMEOUT=30\n");

    $this->artisan('env:fill', $options)
        ->expectsQuestion('Variable to set', '')
        ->doesntExpectOutputToContain('Run config:clear')
        ->assertExitCode(BaseCommand::SUCCESS);

    expect(file_get_contents($options['--file']))->toBe($original)
        ->and($this->refresher->calls)->toBe(0);
});

it('offers the example value and writes an empty variable on an empty answer', function (): void {
    $options = envFillFixture($this->envDir, "APP_ENV=production\n", "APP_ENV=local\n# Seconds an embedding request may take.\nAI_TIMEOUT=30\nAI_OTHER=1\n");

    $this->artisan('env:fill', $options)
        ->expectsQuestion('Variable to set', 'AI_TIMEOUT')
        ->expectsQuestion('AI_TIMEOUT', '')
        ->expectsQuestion('Variable to set', '')
        ->assertExitCode(BaseCommand::SUCCESS);

    expect(file_get_contents($options['--file']))->toBe("APP_ENV=production\nAI_TIMEOUT=\n");
});

it('asks a secret name with the masked prompt and never prints the value', function (): void {
    $options = envFillFixture($this->envDir, "APP_ENV=production\n", "APP_ENV=local\nVOYAGEAI_API_KEY=\n");

    $this->artisan('env:fill', $options)
        ->expectsQuestion('Variable to set', 'VOYAGEAI_API_KEY')
        ->expectsQuestion('VOYAGEAI_API_KEY', 'sk-very-secret-value')
        ->doesntExpectOutputToContain('sk-very-secret-value')
        ->assertExitCode(BaseCommand::SUCCESS);

    expect(file_get_contents($options['--file']))->toBe("APP_ENV=production\nVOYAGEAI_API_KEY=sk-very-secret-value\n");
});

it('writes a value with spaces and quotes so that it reads back unchanged', function (): void {
    $options = envFillFixture($this->envDir, "APP_ENV=production\n", "APP_ENV=local\nAPP_LOGO=\n");

    $this->artisan('env:fill', $options)
        ->expectsQuestion('Variable to set', 'APP_LOGO')
        ->expectsQuestion('APP_LOGO', 'my "logo" $1 #x')
        ->assertExitCode(BaseCommand::SUCCESS);

    expect(Dotenv\Dotenv::parse((string) file_get_contents($options['--file']))['APP_LOGO'])->toBe('my "logo" $1 #x');
});

it('asks to refresh the cache after a write and refreshes it on yes', function (): void {
    app()->setBasePath($this->envDir);
    $options = envFillFixture($this->envDir, "APP_ENV=production\n", "APP_ENV=local\nAI_TIMEOUT=30\n");

    $this->artisan('env:fill')
        ->expectsQuestion('Variable to set', 'AI_TIMEOUT')
        ->expectsQuestion('AI_TIMEOUT', '45')
        ->expectsConfirmation('Run config:clear and config:cache now?', 'yes')
        ->assertExitCode(BaseCommand::SUCCESS);

    expect($this->refresher->calls)->toBe(1)
        ->and(file_get_contents($options['--file']))->toBe("APP_ENV=production\nAI_TIMEOUT=45\n");
});

it('does not refresh the cache on no', function (): void {
    app()->setBasePath($this->envDir);
    envFillFixture($this->envDir, "APP_ENV=production\n", "APP_ENV=local\nAI_TIMEOUT=30\n");

    $this->artisan('env:fill')
        ->expectsQuestion('Variable to set', 'AI_TIMEOUT')
        ->expectsQuestion('AI_TIMEOUT', '45')
        ->expectsConfirmation('Run config:clear and config:cache now?', 'no')
        ->assertExitCode(BaseCommand::SUCCESS);

    expect($this->refresher->calls)->toBe(0);
});

it('never asks the cache question for a file other than the application .env', function (): void {
    $options = envFillFixture($this->envDir, "APP_ENV=production\n", "APP_ENV=local\nAI_TIMEOUT=30\n");

    $this->artisan('env:fill', $options)
        ->expectsQuestion('Variable to set', 'AI_TIMEOUT')
        ->expectsQuestion('AI_TIMEOUT', '45')
        ->doesntExpectOutputToContain('Run config:clear')
        ->assertExitCode(BaseCommand::SUCCESS);

    expect($this->refresher->calls)->toBe(0);
});

it('defaults the cache question to yes in production and no elsewhere', function (): void {
    $command = app(EnvFillCommand::class);

    app()->detectEnvironment(static fn (): string => 'production');
    expect($command->refreshesCacheByDefault())->toBeTrue();

    app()->detectEnvironment(static fn (): string => 'local');
    expect($command->refreshesCacheByDefault())->toBeFalse();
});

it('exits 1 with the reason when the file is missing and creates nothing', function (): void {
    file_put_contents($this->envDir . '/.env.example', "A=1\n");

    $this->artisan('env:fill', ['--file' => $this->envDir . '/.env', '--example' => $this->envDir . '/.env.example'])
        ->expectsOutputToContain('cannot be read')
        ->assertExitCode(BaseCommand::FAILURE);

    expect(file_exists($this->envDir . '/.env'))->toBeFalse();
});

it('exits 1 with the reason when the file is read-only', function (): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('A superuser can write to a read-only file.');
    }

    $options = envFillFixture($this->envDir, "A=1\n", "A=1\nB=2\n");
    chmod($options['--file'], 0o400);

    $this->artisan('env:fill', $options)
        ->expectsOutputToContain('cannot be written')
        ->assertExitCode(BaseCommand::FAILURE);

    expect(file_get_contents($options['--file']))->toBe("A=1\n");
});

it('exits 1 and writes nothing with --no-interaction', function (): void {
    $options = envFillFixture($this->envDir, "A=1\n", "A=1\nB=2\n");

    $this->artisan('env:fill', [...$options, '--no-interaction' => true])
        ->expectsOutputToContain('1 variable missing')
        ->assertExitCode(BaseCommand::FAILURE);

    expect(file_get_contents($options['--file']))->toBe("A=1\n");
});

it('writes the example text itself when the offered default is accepted, so an interpolation survives', function (): void {
    $options = envFillFixture($this->envDir, "APP_NAME=Laraplate\n", "APP_NAME=Laraplate\n# MAIL_FROM_NAME=\"\${APP_NAME}\"\n");

    $this->artisan('env:fill', $options)
        ->expectsQuestion('Variable to set', 'MAIL_FROM_NAME')
        ->expectsQuestion('MAIL_FROM_NAME', '${APP_NAME}')
        ->assertExitCode(BaseCommand::SUCCESS);

    expect(file_get_contents($options['--file']))->toBe("APP_NAME=Laraplate\nMAIL_FROM_NAME=\"\${APP_NAME}\"\n");
});

it('exits 1 and writes nothing when there is no terminal to ask on', function (): void {
    if (defined('STDIN') && stream_isatty(STDIN)) {
        $this->markTestSkipped('This run has a terminal.');
    }

    app()->detectEnvironment(static fn (): string => 'local');
    $options = envFillFixture($this->envDir, "A=1\n", "A=1\nB=2\n");

    $this->artisan('env:fill', $options)
        ->expectsOutputToContain('no terminal')
        ->assertExitCode(BaseCommand::FAILURE);

    expect(file_get_contents($options['--file']))->toBe("A=1\n");
});

it('exits 1 for a read-only file even when nothing is missing', function (): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('A superuser can write to a read-only file.');
    }

    $options = envFillFixture($this->envDir, "A=1\n", "A=1\n");
    chmod($options['--file'], 0o400);

    $this->artisan('env:fill', $options)
        ->expectsOutputToContain('cannot be written')
        ->assertExitCode(BaseCommand::FAILURE);
});

it('says so and exits 1 when the cache could not be rebuilt, instead of reporting it rebuilt', function (): void {
    app()->setBasePath($this->envDir);
    $this->refresher->fails = true;
    envFillFixture($this->envDir, "APP_ENV=production\n", "APP_ENV=local\nAI_TIMEOUT=30\n");

    $this->artisan('env:fill')
        ->expectsQuestion('Variable to set', 'AI_TIMEOUT')
        ->expectsQuestion('AI_TIMEOUT', '45')
        ->expectsConfirmation('Run config:clear and config:cache now?', 'yes')
        ->doesntExpectOutputToContain('was rebuilt')
        ->expectsOutputToContain('config:cache failed')
        ->assertExitCode(BaseCommand::FAILURE);
});
