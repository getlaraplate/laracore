<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\CronJob;

beforeEach(function (): void {
    $this->cronJob = CronJob::factory()->create();
});

it('can be created with factory', function (): void {
    expect($this->cronJob)->toBeInstanceOf(CronJob::class);
    expect($this->cronJob->id)->not->toBeNull();
});

it('has fillable attributes', function (): void {
    $uniqueName = 'test-cron-job-' . uniqid();
    $cronJobData = [
        'name' => $uniqueName,
        'command' => 'test:command',
        'parameters' => ['--param1=value1'],
        'schedule' => '0 0 * * *',
        'description' => 'Test cron job description',
    ];

    $cronJob = CronJob::create($cronJobData);
    $cronJob->setAttribute('is_active', true);
    $cronJob->save();

    expect($cronJob->name)->toBe($uniqueName);
    expect($cronJob->command)->toBe('test:command');
    expect($cronJob->parameters)->toBe(['--param1=value1']);
    expect($cronJob->schedule->getExpression())->toBe('0 0 * * *');
    expect($cronJob->description)->toBe('Test cron job description');
    expect($cronJob->is_active)->toBeTrue();
});

it('has default parameters as an empty list', function (): void {
    $cronJob = CronJob::factory()->create();

    // parameters is cast to json, so it's decoded as array when accessed
    // The default value is '[]': the parameters are a list of strings
    $parameters = $cronJob->getAttributes()['parameters'] ?? json_encode($cronJob->parameters);
    expect($parameters)->toBe('[]');
    expect(json_decode($parameters, true))->toBe([]);
});

it('has validation rules for creation', function (): void {
    $rules = $this->cronJob->getRules();

    expect($rules['create'])->toHaveKey('name');
    expect($rules['always'] ?? [])->toHaveKey('command');
    expect($rules['always'] ?? [])->toHaveKey('schedule');
    expect($rules['always'] ?? [])->toHaveKey('is_active');
    expect(in_array('required', $rules['create']['name'], true))->toBeTrue();
    expect(in_array('string', $rules['create']['name'], true))->toBeTrue();
});

it('has validation rules for update', function (): void {
    $rules = $this->cronJob->getRules();

    expect($rules['update'])->toHaveKey('name');
    expect($rules['always'] ?? [])->toHaveKey('command');
    expect($rules['always'] ?? [])->toHaveKey('schedule');
    expect($rules['always'] ?? [])->toHaveKey('is_active');
    expect(in_array('sometimes', $rules['update']['name'], true))->toBeTrue();
});

it('validates cron expression format', function (): void {
    $uniqueName1 = 'invalid-cron-test-' . uniqid();
    $uniqueName2 = 'valid-cron-test-' . uniqid();

    expect(fn () => CronJob::create([
        'name' => $uniqueName1,
        'command' => 'test:command',
        'schedule' => 'invalid-cron',
    ]))->toThrow(ValidationException::class);

    expect(fn () => CronJob::create([
        'name' => $uniqueName2,
        'command' => 'test:command',
        'schedule' => '0 0 * * *',
    ]))->not->toThrow(ValidationException::class);
});

it('runs on one server and without overlapping by default', function (): void {
    $cronJob = CronJob::factory()->create()->fresh();

    expect($cronJob->on_one_server)->toBeTrue()
        ->and($cronJob->without_overlapping)->toBeTrue();
});

it('rejects a command that carries arguments', function (): void {
    expect(fn () => CronJob::create([
        'name' => 'arguments-in-command-' . uniqid(),
        'command' => 'model:prune --model=App\\Models\\User',
        'schedule' => '0 0 * * *',
    ]))->toThrow(ValidationException::class);
});

it('rejects parameters that are not a list of strings', function (array|string $parameters): void {
    expect(fn () => CronJob::create([
        'name' => 'bad-parameters-' . uniqid(),
        'command' => 'test:command',
        'parameters' => $parameters,
        'schedule' => '0 0 * * *',
    ]))->toThrow(ValidationException::class);
})->with([
    'an object of options' => [['--limit' => 10]],
    'a number among the strings' => [['--all', 5]],
    'a flag that would be run by the shell' => [['--all; rm -rf /']],
    'an option value that starts with a dash' => [['--offset=-5']],
    'text that is not JSON' => ['--all'],
]);

it('has soft deletes trait', function (): void {
    $this->cronJob->delete();

    expect($this->cronJob->trashed())->toBeTrue();
    expect(CronJob::withTrashed()->find($this->cronJob->id))->not->toBeNull();
});

it('has versions trait', function (): void {
    expect(method_exists($this->cronJob, 'versions'))->toBeTrue();
    expect(method_exists($this->cronJob, 'createVersion'))->toBeTrue();
});

it('has locks trait', function (): void {
    expect(method_exists($this->cronJob, 'lock'))->toBeTrue();
    expect(method_exists($this->cronJob, 'unlock'))->toBeTrue();
});

it('has validations trait', function (): void {
    expect(method_exists($this->cronJob, 'getRules'))->toBeTrue();
});

it('can be created with specific attributes', function (): void {
    $uniqueName = 'custom-cron-job-' . uniqid();
    $cronJobData = [
        'name' => $uniqueName,
        'command' => 'custom:command',
        'parameters' => ['--custom=param'],
        'schedule' => '*/5 * * * *',
        'description' => 'Custom cron job',
    ];

    $cronJob = CronJob::create($cronJobData);
    $cronJob->setAttribute('is_active', false);
    $cronJob->save();

    expect($cronJob->name)->toBe($uniqueName);
    expect($cronJob->command)->toBe('custom:command');
    expect($cronJob->parameters)->toBe(['--custom=param']);
    expect($cronJob->schedule->getExpression())->toBe('*/5 * * * *');
    expect($cronJob->description)->toBe('Custom cron job');
    expect($cronJob->is_active)->toBeFalse();
});

it('can be found by name', function (): void {
    $uniqueName = 'unique-cron-job-' . uniqid();
    $cronJob = CronJob::factory()->create(['name' => $uniqueName]);

    $foundCronJob = CronJob::where('name', $uniqueName)->first();

    expect($foundCronJob->id)->toBe($cronJob->id);
});

it('can be found by command', function (): void {
    $uniqueCommand = 'unique:command-' . uniqid();
    $cronJob = CronJob::factory()->create(['command' => $uniqueCommand]);

    $foundCronJob = CronJob::where('command', $uniqueCommand)->first();

    expect($foundCronJob->id)->toBe($cronJob->id);
});

it('can be found by active status', function (): void {
    $uniqueName1 = 'active-test-' . uniqid();
    $uniqueName2 = 'inactive-test-' . uniqid();
    $activeCronJob = CronJob::factory()->create(['name' => $uniqueName1]);
    $activeCronJob->setAttribute('is_active', true);
    $activeCronJob->save();

    $inactiveCronJob = CronJob::factory()->create(['name' => $uniqueName2]);
    $inactiveCronJob->setAttribute('is_active', false);
    $inactiveCronJob->save();

    $activeCronJobs = CronJob::where('is_active', true)->get();
    $inactiveCronJobs = CronJob::where('is_active', false)->get();

    expect($activeCronJobs->contains('id', $activeCronJob->id))->toBeTrue();
    expect($inactiveCronJobs->contains('id', $inactiveCronJob->id))->toBeTrue();
});

it('can be found by schedule', function (): void {
    $uniqueName = 'schedule-test-' . uniqid();
    $cronJob = CronJob::factory()->create([
        'name' => $uniqueName,
        'schedule' => '0 0 * * *',
    ]);

    $foundCronJob = CronJob::where('schedule', '0 0 * * *')->where('name', $uniqueName)->first();

    expect($foundCronJob)->not->toBeNull();
    expect($foundCronJob->id)->toBe($cronJob->id);
    expect($foundCronJob->schedule->getExpression())->toBe('0 0 * * *');
});

it('has proper timestamps', function (): void {
    $cronJob = CronJob::factory()->create();

    expect($cronJob->created_at)->toBeInstanceOf(Carbon\CarbonInterface::class);
    expect($cronJob->updated_at)->toBeInstanceOf(Carbon\CarbonInterface::class);
});

it('can be serialized to array', function (): void {
    $uniqueName = 'test-job-' . uniqid();
    $cronJob = CronJob::factory()->create([
        'name' => $uniqueName,
        'command' => 'test:command',
    ]);
    $cronJob->setAttribute('is_active', true);
    $cronJob->save();
    $cronJobArray = $cronJob->toArray();

    expect($cronJobArray)->toHaveKey('id');
    expect($cronJobArray)->toHaveKey('name');
    expect($cronJobArray)->toHaveKey('command');
    expect($cronJobArray)->toHaveKey('schedule');
    // schedule is cast to CronExpression object, so it's serialized as string in array
    expect($cronJobArray)->toHaveKey('created_at');
    expect($cronJobArray)->toHaveKey('updated_at');
    expect($cronJobArray['name'])->toBe($uniqueName);
    expect($cronJobArray['command'])->toBe('test:command');
    // is_active might not be in fillable, so it may not be in serialized array
    // but we can verify it's set on the model
    expect($cronJob->is_active)->toBeTrue();
});

it('can be restored after soft delete', function (): void {
    $cronJob = CronJob::factory()->create();
    $cronJob->delete();

    expect($cronJob->trashed())->toBeTrue();

    $cronJob->restore();

    expect($cronJob->trashed())->toBeFalse();
});

it('can be permanently deleted', function (): void {
    $cronJob = CronJob::factory()->create();
    $cronJobId = $cronJob->id;

    $cronJob->forceDelete();

    expect(CronJob::withTrashed()->find($cronJobId))->toBeNull();
});

it('reports the previous and next due times of its schedule', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 10:30:00'));
    $cron_job = CronJob::factory()->create(['schedule' => '0 * * * *', 'is_active' => true]);

    expect($cron_job->previousRunAt()?->format('Y-m-d H:i'))->toBe('2026-11-25 10:00')
        ->and($cron_job->nextRunAt()?->format('Y-m-d H:i'))->toBe('2026-11-25 11:00');
});

it('has no due times while inactive', function (): void {
    $cron_job = CronJob::factory()->create(['schedule' => '0 * * * *', 'is_active' => false]);

    expect($cron_job->previousRunAt())->toBeNull()
        ->and($cron_job->nextRunAt())->toBeNull();
});
