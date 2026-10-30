<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('profiles the given endpoint and reports latency columns', function (): void {
    Route::get('/perf-cmd-probe', fn () => response()->json(['ok' => true]));

    $this->artisan('test:perf:bench', [
        'endpoint' => ['GET:/perf-cmd-probe'],
        '--iterations' => 2,
        '--warmup' => 0,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('/perf-cmd-probe')
        ->expectsOutputToContain('p95');
});

it('fails clearly on a malformed endpoint spec', function (): void {
    $this->artisan('test:perf:bench', [
        'endpoint' => ['not-a-valid-spec'],
    ])->assertFailed();
});

it('profiles as an authenticated user when --user is given', function (): void {
    Route::get('/perf-cmd-auth', fn (Request $request) => $request->user() !== null
        ? response()->json(['ok' => true], 200)
        : response('no', 403));

    $user = User::factory()->create();

    $this->artisan('test:perf:bench', [
        'endpoint' => ['GET:/perf-cmd-auth'],
        '--user' => (string) $user->getKey(),
        '--iterations' => 2,
        '--warmup' => 0,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('200');
});

it('fails when --user id does not exist', function (): void {
    $this->artisan('test:perf:bench', [
        'endpoint' => ['GET:/whatever'],
        '--user' => '999999',
    ])->assertFailed();
});
