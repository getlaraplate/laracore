<?php

declare(strict_types=1);

use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Models\Version;

/**
 * The history route answers for the one record its key names: the record and its versions, never the versions of
 * another row of the same table, and never a "several records matched" error because the key was left out of the query.
 */
function crudHistoryRouteParams(): array
{
    return ['module' => 'core', 'entity' => 'users'];
}

beforeEach(function (): void {
    $this->actor = User::factory()->create();
    $this->actor->assignRole(Role::findOrCreate('superadmin', 'web'));
    $this->actingAs($this->actor);
});

function crudHistoryVersionedUser(string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    $versioned = User::query()->withoutGlobalScopes()->findOrFail($user->getKey());
    $versioned->versionStrategy = VersionStrategy::SNAPSHOT;

    Version::createForModel($versioned, [], null, VersionStrategy::SNAPSHOT);

    return $user;
}

it('answers the history of the record the key names, not of another row', function (): void {
    $first = crudHistoryVersionedUser('first');
    $second = crudHistoryVersionedUser('second');

    expect($first->versions()->count())->toBe(1)
        ->and($second->versions()->count())->toBe(1);

    $response = $this->getJson(
        route('core.crud.history', crudHistoryRouteParams()) . '?' . http_build_query(['id' => $second->id]),
    );

    $response->assertOk();

    expect($response->json('data.record.id'))->toBe($second->id)
        ->and($response->json('data.history'))->not->toBeEmpty();

    $history_ids = collect($response->json('data.history'))->pluck('id')->sort()->values()->all();

    expect($history_ids)->toBe($second->versions()->pluck('id')->sort()->values()->all());
});
