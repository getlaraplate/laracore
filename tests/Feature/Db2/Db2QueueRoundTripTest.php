<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Setting;

it('pushes a job on the database queue and works it off', function (): void {
    config()->set('queue.default', 'database');
    $name = 'db2.queue.' . uniqid();

    dispatch(static function () use ($name): void {
        Setting::query()->forceCreate(['name' => $name, 'value' => 'worked', 'description' => '']);
    });

    expect(DB::table('jobs')->count())->toBe(1);

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true, '--no-interaction' => true]);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(Setting::query()->where('name', $name)->exists())->toBeTrue();
})->group('db2');
