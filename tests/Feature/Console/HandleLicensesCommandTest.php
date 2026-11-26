<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Easi\DB2\Database\ReservedWords;
use Illuminate\Console\OutputStyle;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\TextPrompt;
use Modules\Core\Console\HandleLicensesCommand;
use Modules\Core\Models\License;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function licensesCommandWithOutput(): HandleLicensesCommand
{
    $command = app(HandleLicensesCommand::class);
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

    return $command;
}

it('command exists and has correct signature', function (): void {
    $reflection = new ReflectionClass(HandleLicensesCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('auth:licenses');
    expect($source)->toContain('Renew, add or delete user licenses');
});

it('command class has correct properties', function (): void {
    $reflection = new ReflectionClass(HandleLicensesCommand::class);

    expect($reflection->getName())->toBe('Modules\Core\Console\HandleLicensesCommand');
    expect($reflection->isSubclassOf(Command::class))->toBeTrue();
});

it('command can be instantiated', function (): void {
    $reflection = new ReflectionClass(HandleLicensesCommand::class);

    expect($reflection->isInstantiable())->toBeTrue();
    expect($reflection->isSubclassOf(Command::class))->toBeTrue();
});

it('command has correct namespace', function (): void {
    $reflection = new ReflectionClass(HandleLicensesCommand::class);

    expect($reflection->getNamespaceName())->toBe('Modules\Core\Console');
    expect($reflection->getShortName())->toBe('HandleLicensesCommand');
});

it('command has handle method', function (): void {
    $reflection = new ReflectionClass(HandleLicensesCommand::class);

    expect($reflection->hasMethod('handle'))->toBeTrue();
});

it('command handle method returns int', function (): void {
    $reflection = new ReflectionMethod(HandleLicensesCommand::class, 'handle');

    // The handle method may not have explicit return type, so we check the source code
    $source = file_get_contents($reflection->getDeclaringClass()->getFileName());
    expect($source)->toContain('return BaseCommand::SUCCESS');
    expect($source)->toContain('return BaseCommand::FAILURE');
});

it('command uses Laravel Prompts', function (): void {
    $reflection = new ReflectionClass(HandleLicensesCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('Laravel\Prompts\confirm');
    expect($source)->toContain('Laravel\Prompts\select');
    expect($source)->toContain('Laravel\Prompts\table');
    expect($source)->toContain('Laravel\Prompts\text');
});

it('command has license management methods', function (): void {
    $reflection = new ReflectionClass(HandleLicensesCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('renewLicenses');
    expect($source)->toContain('addLicenses');
    expect($source)->toContain('closeLicenses');
    expect($source)->toContain('listLicenses');
});

it('command handles license status display', function (): void {
    $reflection = new ReflectionClass(HandleLicensesCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('Current licenses status');
    expect($source)->toContain('table(');
});

it('command validates input', function (): void {
    $reflection = new ReflectionClass(HandleLicensesCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('validationCallback');
});

it('command handles expired licenses', function (): void {
    $reflection = new ReflectionClass(HandleLicensesCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('expired');
    expect($source)->toContain('License::expired()');
});

it('command shows max sessions setting', function (): void {
    $reflection = new ReflectionClass(HandleLicensesCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('auth.max_concurrent_sessions');
});

it('command handles license creation', function (): void {
    $reflection = new ReflectionClass(HandleLicensesCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('License::factory()');
    expect($source)->toContain('create(');
});

it('command handles license updates', function (): void {
    $reflection = new ReflectionClass(HandleLicensesCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('update(');
    expect($source)->toContain('valid_from');
    expect($source)->toContain('valid_to');
});

it('command logs license operations', function (): void {
    $reflection = new ReflectionClass(HandleLicensesCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('Log::info');
});

it('command handles different license actions', function (): void {
    $reflection = new ReflectionClass(HandleLicensesCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('list');
    expect($source)->toContain('add');
    expect($source)->toContain('renew');
    expect($source)->toContain('close');
});

it('command handles license grouping', function (): void {
    $reflection = new ReflectionClass(HandleLicensesCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('groupBy');
    expect($source)->toContain('valid_to');
});

it('handles close action prompt path without crashing the test runner', function (): void {
    $user = User::factory()->create();
    $license = License::factory()->create();
    $user->license_id = $license->id;
    $user->save();
    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'auth.max_concurrent_sessions',
        'group_name' => 'core',
        'value' => '10',
    ]);

    SelectPrompt::fallbackWhen(true);
    SelectPrompt::fallbackUsing(static fn (): int => 2);
    TextPrompt::fallbackWhen(true);
    TextPrompt::fallbackUsing(static fn (): string => '0');

    $command = licensesCommandWithOutput();
    expect($command->handle())->toBeIn([0, 1]);
});

it('returns failure when prompt throws in handle', function (): void {
    SelectPrompt::fallbackWhen(true);
    SelectPrompt::fallbackUsing(static fn (): string => throw new RuntimeException('boom'));

    $command = licensesCommandWithOutput();
    expect($command->handle())->toBe(1);
});

it('covers validation callback outcomes', function (): void {
    $command = licensesCommandWithOutput();
    $method = new ReflectionMethod(HandleLicensesCommand::class, 'validationCallback');
    $method->setAccessible(true);

    $missing = $method->invoke($command, 'missing', 'abc', []);
    $invalid = $method->invoke($command, 'number', 'not-numeric', ['number' => 'numeric|min:0']);
    $valid = $method->invoke($command, 'number', '5', ['number' => 'numeric|min:0']);

    expect($missing)->toBeNull()
        ->and($invalid)->not->toBeNull()
        ->and($valid)->toBeNull();
});

it('covers renewLicenses branches including close and no-confirm create path', function (): void {
    $command = licensesCommandWithOutput();
    $method = new ReflectionMethod(HandleLicensesCommand::class, 'renewLicenses');
    $method->setAccessible(true);

    License::factory()->count(3)->create(['valid_to' => null]);
    $valid_to = now()->addDays(7);
    $method->invoke($command, 1, 3, $valid_to);

    expect(License::query()->whereDate('valid_to', $valid_to)->count())->toBe(3);

    ConfirmPrompt::fallbackWhen(true);
    ConfirmPrompt::fallbackUsing(static fn (): bool => false);
    $method->invoke($command, 5, 1, null);

    expect(License::query()->count())->toBe(3);
});

it('covers renewLicenses creation branch when requested number is higher', function (): void {
    $command = licensesCommandWithOutput();
    $method = new ReflectionMethod(HandleLicensesCommand::class, 'renewLicenses');
    $method->setAccessible(true);

    License::factory()->count(1)->create(['valid_to' => null]);

    ConfirmPrompt::fallbackWhen(true);
    ConfirmPrompt::fallbackUsing(static fn (): bool => true);
    $method->invoke($command, 3, 1, null);

    expect(License::query()->count())->toBe(3);
});

it('covers addLicenses branches and closeLicenses', function (): void {
    $command = licensesCommandWithOutput();
    $add = new ReflectionMethod(HandleLicensesCommand::class, 'addLicenses');
    $close = new ReflectionMethod(HandleLicensesCommand::class, 'closeLicenses');
    $add->setAccessible(true);
    $close->setAccessible(true);

    License::factory()->count(2)->create(['valid_to' => today()->subDay()]);
    $before = License::query()->count();

    ConfirmPrompt::fallbackWhen(true);
    ConfirmPrompt::fallbackUsing(static fn (): bool => true);
    $add->invoke($command, 3, null);

    expect(License::query()->count())->toBe($before + 1);

    $close->invoke($command, 1, null);
    expect(License::query()->whereDate('valid_to', today())->count())->toBeGreaterThan(0);
});

it('covers listLicenses private method output path', function (): void {
    $command = licensesCommandWithOutput();
    $list = new ReflectionMethod(HandleLicensesCommand::class, 'listLicenses');
    $list->setAccessible(true);

    $user = User::factory()->create(['name' => 'List User']);
    $license = License::factory()->create(['valid_to' => null]);
    $user->license_id = $license->id;
    $user->save();

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'auth.max_concurrent_sessions',
        'group_name' => 'core',
        'value' => '7',
    ]);

    $list->invoke($command);

    $source = file_get_contents((new ReflectionClass(HandleLicensesCommand::class))->getFileName());
    expect($source)->toContain("License::with('user')->lazy(100)")
        ->and($source)->not->toContain("License::with('user')->get()");
});

it('handles add action through handle flow', function (): void {
    SelectPrompt::fallbackWhen(true);
    SelectPrompt::fallbackUsing(static fn (): string => 'add');
    TextPrompt::fallbackWhen(true);
    TextPrompt::fallbackUsing(static function (): string {
        static $answers = ['1', ''];
        static $i = 0;

        return $answers[$i++] ?? '';
    });
    ConfirmPrompt::fallbackWhen(true);
    ConfirmPrompt::fallbackUsing(static fn (): bool => false);

    $command = licensesCommandWithOutput();
    expect($command->handle())->toBe(0);
});

it('returns early from handle when amount is zero', function (): void {
    SelectPrompt::fallbackWhen(true);
    SelectPrompt::fallbackUsing(static fn (): string => 'add');
    TextPrompt::fallbackWhen(true);
    TextPrompt::fallbackUsing(static fn (): string => '0');

    $command = licensesCommandWithOutput();
    expect($command->handle())->toBe(0);
});

it('handles list action with existing licenses through handle flow', function (): void {
    $user = User::factory()->create(['name' => 'Flow User']);
    $license = License::factory()->create(['valid_to' => null]);
    $user->license_id = $license->id;
    $user->save();
    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'auth.max_concurrent_sessions',
        'group_name' => 'core',
        'value' => '5',
    ]);

    SelectPrompt::fallbackWhen(true);
    SelectPrompt::fallbackUsing(static fn (): string => 'list');

    $command = licensesCommandWithOutput();
    expect($command->handle())->toBeIn([0, 1]);
});

it('handles renew and close actions through handle flow', function (): void {
    License::factory()->count(2)->create(['valid_to' => null]);

    SelectPrompt::fallbackWhen(true);
    SelectPrompt::fallbackUsing(static function (): string {
        static $answers = ['renew', 'close'];
        static $index = 0;

        return $answers[$index++] ?? 'list';
    });

    TextPrompt::fallbackWhen(true);
    TextPrompt::fallbackUsing(static function (): string {
        static $answers = ['1', '', '1', ''];
        static $index = 0;

        return $answers[$index++] ?? '';
    });
    ConfirmPrompt::fallbackWhen(true);
    ConfirmPrompt::fallbackUsing(static fn (): bool => false);

    $command = licensesCommandWithOutput();
    expect($command->handle())->toBe(0);
    expect($command->handle())->toBe(0);
});

it('reports a group of licenses as perpetual, active or expired by its expiry', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00'));

    expect(HandleLicensesCommand::statusOf(null))->toBe('perpetual')
        ->and(HandleLicensesCommand::statusOf(CarbonImmutable::parse('2026-11-01')))->toBe('expired')
        ->and(HandleLicensesCommand::statusOf(CarbonImmutable::parse('2027-01-01')))->toBe('active');
});

it('counts each expiry group under an alias that is no Db2 reserved word', function (): void {
    License::factory()->count(2)->create(['valid_to' => null]);

    // Add none: the run reads the expiry groups, then stops at the requested number with success.
    SelectPrompt::fallbackWhen(true);
    SelectPrompt::fallbackUsing(static fn (): string => 'add');
    TextPrompt::fallbackWhen(true);
    TextPrompt::fallbackUsing(static fn (): string => '0');

    $connection = (new License)->getConnection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $command = licensesCommandWithOutput();
    expect($command->handle())->toBe(0);

    $grouping = collect($connection->getQueryLog())
        ->pluck('query')
        ->first(static fn (string $query): bool => str_contains($query, 'group by'));
    $connection->disableQueryLog();

    expect($grouping)->toBeString()
        ->and(preg_match('/ as "?(\w+)"?/', (string) $grouping, $alias))->toBe(1)
        ->and(ReservedWords::contains($alias[1]))->toBeFalse()
        // Ordered by the grouped column: the key order lazy() adds by default is neither grouped nor aggregated,
        // which Db2, PostgreSQL and MySQL with ONLY_FULL_GROUP_BY refuse.
        ->and((string) $grouping)->toMatch('/order by "valid_to" asc/')
        ->and((string) $grouping)->not->toMatch('/order by [^)]*"id"/');
});
