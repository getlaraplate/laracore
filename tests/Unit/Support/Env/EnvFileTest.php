<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Modules\Core\Support\Env\EnvEntry;
use Modules\Core\Support\Env\EnvFile;

beforeEach(function (): void {
    $this->envDir = sys_get_temp_dir() . '/env-file-test-' . bin2hex(random_bytes(6));
    mkdir($this->envDir, 0o700, true);
});

afterEach(function (): void {
    chmod($this->envDir, 0o700);

    foreach (glob($this->envDir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    rmdir($this->envDir);
});

/**
 * Writes `$content` to a file of the test directory and returns its path.
 */
function envFileFixture(string $directory, string $content, string $name = '.env', int $mode = 0o600): string
{
    $path = $directory . '/' . $name;
    file_put_contents($path, $content);
    chmod($path, $mode);

    return $path;
}

it('reads active, commented, quoted and empty entries and the comment block above each', function (): void {
    $path = envFileFixture($this->envDir, <<<'ENV'
        APP_NAME="Laraplate"

        # Seconds an embedding request may take.
        # Raise it on a slow CPU.
        AI_TIMEOUT=30
        # AI_BATCH=32
        EMPTY=
        QUOTED='single quoted'
        ENV);

    $entries = EnvFile::read($path)->entries();

    expect($entries)->toHaveCount(5)
        ->and($entries[0])->toEqual(new EnvEntry('APP_NAME', 'Laraplate', '"Laraplate"', false, ''))
        ->and($entries[1])->toEqual(new EnvEntry('AI_TIMEOUT', '30', '30', false, 'Seconds an embedding request may take. Raise it on a slow CPU.'))
        ->and($entries[2])->toEqual(new EnvEntry('AI_BATCH', '32', '32', true, ''))
        ->and($entries[3])->toEqual(new EnvEntry('EMPTY', '', '', false, ''))
        ->and($entries[4])->toEqual(new EnvEntry('QUOTED', 'single quoted', "'single quoted'", false, ''));
});

it('ignores prose comments that are not NAME= lines', function (): void {
    $path = envFileFixture($this->envDir, "# Read the guide first\n# See https://example.com/a=b for details\nAPP_ENV=local\n");

    $file = EnvFile::read($path);

    expect($file->entries())->toHaveCount(1)
        ->and($file->entry('APP_ENV')?->comment)->toBe('Read the guide first See https://example.com/a=b for details');
});

it('counts an empty value as defined and a commented line as missing', function (): void {
    $env = EnvFile::read(envFileFixture($this->envDir, "EMPTY=\n# COMMENTED=1\nSET=x\n"));
    $example = EnvFile::read(envFileFixture($this->envDir, "EMPTY=\nCOMMENTED=1\nSET=y\nNEW=1\n", '.env.example'));

    expect($env->definedNames())->toBe(['EMPTY', 'SET'])
        ->and($env->missingFrom($example))->toBe(['COMMENTED', 'NEW']);
});

it('lists the missing names in example order, once each', function (): void {
    $env = EnvFile::read(envFileFixture($this->envDir, "B=1\n"));
    $example = EnvFile::read(envFileFixture($this->envDir, "C=1\n# A=2\nB=1\nC=2\n# D=3\nA=4\n", '.env.example'));

    expect($env->missingFrom($example))->toBe(['C', 'A', 'D']);
});

it('reads names after a multi-line quoted value correctly', function (): void {
    $path = envFileFixture($this->envDir, "PRIVATE_KEY=\"line1\nFAKE_NAME=inside\nline3\"\nAFTER=1\n");

    expect(EnvFile::read($path)->definedNames())->toBe(['PRIVATE_KEY', 'AFTER']);
});

it('appends NAME=value and leaves every other byte of the file as it was', function (): void {
    $original = "APP_ENV=production\n\n# keep this\nDB_HOST=db  \n";
    $path = envFileFixture($this->envDir, $original);

    $file = EnvFile::read($path);
    $file->append('AI_TIMEOUT', '45');

    expect(file_get_contents($path))->toBe($original . "AI_TIMEOUT=45\n")
        ->and($file->definedNames())->toBe(['APP_ENV', 'DB_HOST', 'AI_TIMEOUT']);
});

it('writes a value that needs quotes so that Dotenv reads it back unchanged', function (): void {
    $path = envFileFixture($this->envDir, "APP_ENV=production\n");

    EnvFile::read($path)->append('APP_LOGO', 'my "logo" $1 #x');

    expect(Dotenv::parse((string) file_get_contents($path))['APP_LOGO'])->toBe('my "logo" $1 #x');
});

it('adds the missing final newline first', function (): void {
    $path = envFileFixture($this->envDir, 'APP_ENV=production');

    EnvFile::read($path)->append('NEW_VAR', '1');

    expect(file_get_contents($path))->toBe("APP_ENV=production\nNEW_VAR=1\n");
});

it('keeps CRLF endings when the file uses them', function (): void {
    $path = envFileFixture($this->envDir, "APP_ENV=production\r\nDB_HOST=db\r\n");

    EnvFile::read($path)->append('NEW_VAR', '1');

    expect(file_get_contents($path))->toBe("APP_ENV=production\r\nDB_HOST=db\r\nNEW_VAR=1\r\n");
});

it('keeps the file mode', function (): void {
    $path = envFileFixture($this->envDir, "A=1\n", '.env', 0o600);

    EnvFile::read($path)->append('B', '2');
    clearstatcache();

    expect(fileperms($path) & 0o777)->toBe(0o600);
});

it('leaves the original untouched and no temporary file when the directory is not writable', function (): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('A superuser can write to a read-only directory.');
    }

    $path = envFileFixture($this->envDir, "A=1\n");
    $file = EnvFile::read($path);
    chmod($this->envDir, 0o500);

    expect(fn () => $file->append('B', '2'))->toThrow(RuntimeException::class);

    chmod($this->envDir, 0o700);

    expect(file_get_contents($path))->toBe("A=1\n")
        ->and(glob($this->envDir . '/.env.*'))->toBe([]);
});

it('refuses to write a read-only file', function (): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('A superuser can write to a read-only file.');
    }

    $path = envFileFixture($this->envDir, "A=1\n", '.env', 0o400);

    expect(fn () => EnvFile::read($path)->append('B', '2'))->toThrow(RuntimeException::class);
    expect(file_get_contents($path))->toBe("A=1\n");
});

it('refuses a name that is not NAME-shaped or is already defined', function (string $name): void {
    $file = EnvFile::read(envFileFixture($this->envDir, "TAKEN=1\n"));

    expect(fn () => $file->append($name, 'x'))->toThrow(InvalidArgumentException::class);
})->with(['lowercase', 'WITH SPACE', '1STARTS_WITH_DIGIT', 'TAKEN', '']);

it('fails to read a missing file', function (): void {
    expect(fn () => EnvFile::read($this->envDir . '/missing'))->toThrow(RuntimeException::class);
});

it('wraps a parse error without the value', function (): void {
    $path = envFileFixture($this->envDir, "SECRET_THING=top secret value\n");

    try {
        EnvFile::read($path)->definedNames();
        $this->fail('The unquoted value with spaces should not parse.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->not->toContain('top secret')
            ->and($exception->getPrevious())->toBeNull();
    }
});

it('does not take a prose comment that merely starts like an entry for a commented variable', function (): void {
    $path = envFileFixture($this->envDir, "# SAO_SYNC_ENABLED=false to keep the command manual-only.\n# SAO_SYNC_ENABLED=true\n# SAO_CRON=\"0 * * * *\"\n");

    $file = EnvFile::read($path);

    expect(array_map(static fn (EnvEntry $entry): string => $entry->name . '=' . $entry->raw, $file->entries()))->toBe(['SAO_SYNC_ENABLED=true', 'SAO_CRON="0 * * * *"'])
        ->and($file->entry('SAO_SYNC_ENABLED')?->comment)->toBe('SAO_SYNC_ENABLED=false to keep the command manual-only.');
});

it('appends the text of an example value as it is written, keeping the interpolation', function (): void {
    $path = envFileFixture($this->envDir, "APP_NAME=Laraplate\n");

    EnvFile::read($path)->appendRaw('MAIL_FROM_NAME', '"${APP_NAME}"');

    expect(file_get_contents($path))->toBe("APP_NAME=Laraplate\nMAIL_FROM_NAME=\"\${APP_NAME}\"\n")
        ->and(Dotenv::parse((string) file_get_contents($path))['MAIL_FROM_NAME'])->toBe('Laraplate');
});

it('writes through a symlink to the file it points to and keeps the link', function (): void {
    mkdir($this->envDir . '/shared');
    mkdir($this->envDir . '/release');
    file_put_contents($this->envDir . '/shared/.env', "A=1\n");
    chmod($this->envDir . '/shared/.env', 0o600);
    symlink($this->envDir . '/shared/.env', $this->envDir . '/release/.env');

    EnvFile::read($this->envDir . '/release/.env')->append('B', '2');

    expect(is_link($this->envDir . '/release/.env'))->toBeTrue()
        ->and(file_get_contents($this->envDir . '/shared/.env'))->toBe("A=1\nB=2\n");

    unlink($this->envDir . '/release/.env');
    unlink($this->envDir . '/shared/.env');
    rmdir($this->envDir . '/shared');
    rmdir($this->envDir . '/release');
});

it('keeps what the file gained on disk after it was read', function (): void {
    $path = envFileFixture($this->envDir, "A=1\n");
    $file = EnvFile::read($path);

    file_put_contents($path, "A=1\nEDITED_ELSEWHERE=yes\n");
    $file->append('B', '2');

    expect(file_get_contents($path))->toBe("A=1\nEDITED_ELSEWHERE=yes\nB=2\n");
});

it('refuses a name another process defined after the file was read', function (): void {
    $path = envFileFixture($this->envDir, "A=1\n");
    $file = EnvFile::read($path);

    file_put_contents($path, "A=1\nB=taken\n");

    expect(fn () => $file->append('B', '2'))->toThrow(InvalidArgumentException::class);
    expect(file_get_contents($path))->toBe("A=1\nB=taken\n");
});

it('never lets a failed write raise a PHP warning or leave a temporary file', function (): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('A superuser can write to a read-only directory.');
    }

    $path = envFileFixture($this->envDir, "A=1\n");
    $file = EnvFile::read($path);
    chmod($this->envDir, 0o500);

    try {
        $file->append('SECRET_KEY', 'top-secret-value');
        $this->fail('The append should have failed.');
    } catch (RuntimeException $exception) {
        expect($exception)->not->toBeInstanceOf(ErrorException::class)
            ->and($exception->getMessage())->not->toContain('top-secret-value');
    } finally {
        chmod($this->envDir, 0o700);
    }

    expect(glob($this->envDir . '/.env.*'))->toBe([]);
});

it('offers no default for a quoted example value that runs over several lines', function (): void {
    $path = envFileFixture($this->envDir, "# PRIVATE_KEY=\"-----BEGIN\n# MORE=1\n");

    $entry = EnvFile::read($path)->entry('PRIVATE_KEY');

    expect($entry?->value)->toBe('')->and($entry?->raw)->toBe('');
});

it('reads an export line of the example as a variable', function (): void {
    $path = envFileFixture($this->envDir, "export REGION=eu\n");

    expect(EnvFile::read($path)->entry('REGION')?->value)->toBe('eu');
});

it('appends a value with multibyte characters', function (): void {
    $path = envFileFixture($this->envDir, "A=1\n");

    EnvFile::read($path)->append('APP_NAME', 'Città 日本');

    expect(Dotenv::parse((string) file_get_contents($path))['APP_NAME'])->toBe('Città 日本');
});
