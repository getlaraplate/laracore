<?php

declare(strict_types=1);

use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * `CrudService::update()`, `delete()` and lock/unlock iterate their matches with `lazy(100)`, which
 * pages with OFFSET over a query the loop itself changes. That is safe only because a modify request
 * names exactly one record by its whole primary key: these tests hold that invariant, so a change
 * that lets a list of keys through has to revisit the iteration first.
 */
beforeEach(function (): void {
    $this->actor = User::factory()->create();
    $this->actor->assignRole(Role::findOrCreate('superadmin', 'web'));
    $this->actingAs($this->actor);
});

it('refuses a list of keys on a modify route and writes nothing', function (string $method, string $route): void {
    $ids = User::factory()->count(3)->create()->pluck('id')->all();

    $response = $this->json($method, route($route, ['module' => 'core', 'entity' => 'users']), ['id' => $ids, 'name' => 'renamed']);

    $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

    $users = User::query()->whereKey($ids)->get();

    expect($users)->toHaveCount(3)
        ->and($users->pluck('name')->contains('renamed'))->toBeFalse()
        ->and($users->filter(static fn (User $user): bool => $user->isLocked()))->toBeEmpty();
})->with([
    'delete' => ['DELETE', 'core.crud.delete'],
    'update' => ['PATCH', 'core.crud.replace'],
    'lock' => ['PATCH', 'core.crud.lock'],
]);
