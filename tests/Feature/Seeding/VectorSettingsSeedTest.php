<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Setting;

uses(RefreshDatabase::class);

it('seeds none of the vector settings, the AI module owns the vector profile', function (): void {
    $this->artisan('db:seed', ['--class' => CoreDatabaseSeeder::class])->assertSuccessful();

    $names = ['enabled', 'model', 'dimensions', 'similarity', 'suspended_reason'];

    foreach ($names as $suffix) {
        expect(Setting::query()->where('name', "search.vector.{$suffix}")->exists())->toBeFalse();
    }

    expect(Setting::query()->where('name', 'like', 'search.vector.%')->count())->toBe(0);
});
