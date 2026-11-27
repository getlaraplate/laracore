<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\Support\PermissionName;
use Modules\Core\Versioning\VersionStrategyCache;

/*
 * `POST /revert/{module}/{entity}` reverts one record to a revision (D56, D62): `revert` on the entity and the record
 * within its ACL, the bin included; a stale revision is a conflict, a refused revert names its reason.
 */

beforeEach(function (): void {
    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'versioning.strategy.' . (new User)->getTable(),
        'type' => 'json',
        'value' => VersionStrategy::DIFF->value,
        'choices' => [false, ...VersionStrategy::cases()],
        'group_name' => 'versioning',
    ]);
    app(PerModelSettingResolver::class)->flush();
    VersionStrategyCache::flush();
});

afterEach(function (): void {
    VersionStrategyCache::flush();
});

/**
 * A user renamed once: the revision of its creation and the current one.
 *
 * @return array{User, int, int}
 */
function revert_route_record(): array
{
    $record = User::factory()->create(['name' => 'First']);
    $created = (int) $record->currentRevision();
    $record->update(['name' => 'Second']);

    return [$record, $created, (int) $record->currentRevision()];
}

/**
 * A user holding the given verbs on users, on the web guard, its select narrowed by the ACL when given.
 *
 * @param  list<string>  $verbs
 */
function revert_route_actor(array $verbs, ?FiltersGroup $acl = null): User
{
    $role = Role::factory()->create(['name' => 'revert_route_' . uniqid(), 'guard_name' => 'web']);

    foreach ($verbs as $verb) {
        $permission = Permission::findOrCreate(PermissionName::forClass(User::class, $verb), 'web');
        $role->givePermissionTo($permission);

        if ($acl instanceof FiltersGroup && $verb === 'revert') {
            $rule = new ACL;
            $rule->setSkipValidation(true);
            $rule->forceFill([
                'permission_id' => $permission->id,
                'role_id' => $role->id,
                'filters' => $acl,
                'unrestricted' => false,
                'priority' => 10,
                'is_active' => true,
            ]);
            $rule->save();
        }
    }

    $actor = User::factory()->create();
    $actor->assignRole($role);

    return $actor->fresh();
}

/**
 * @param  array<string, mixed>  $body
 */
function revert_route_post(object $test, User $actor, array $body): TestResponse
{
    return $test->actingAs($actor)->postJson(route('core.crud.revert', ['module' => 'core', 'entity' => 'users']), $body);
}

it('reverts the record and reports the revert set', function (): void {
    [$record, $created, $current] = revert_route_record();

    $response = revert_route_post($this, revert_route_actor(['select', 'revert']), ['id' => $record->id, 'revision' => $created, 'expected_revision' => $current]);

    $response->assertOk();
    expect($record->fresh()->name)->toBe('First')
        ->and($response->json('data.revert.target_revision'))->toBe($created)
        ->and($response->json('data.revert.revision'))->toBeInt()
        ->and($response->json('data.revert.partial'))->toBeFalse()
        ->and($response->json('data.record.name'))->toBe('First');
});

it('refuses a writer without revert, update included', function (): void {
    [$record, $created, $current] = revert_route_record();

    revert_route_post($this, revert_route_actor(['select', 'update', 'history']), ['id' => $record->id, 'revision' => $created, 'expected_revision' => $current])
        ->assertForbidden();

    expect($record->fresh()->name)->toBe('Second');
});

it('answers a record outside the ACL like a missing one', function (): void {
    [$record, $created, $current] = revert_route_record();
    $actor = revert_route_actor(['select', 'revert'], new FiltersGroup([new Filter('name', ['nobody'], FilterOperator::In)]));

    revert_route_post($this, $actor, ['id' => $record->id, 'revision' => $created, 'expected_revision' => $current])->assertNotFound();
    revert_route_post($this, $actor, ['id' => 987654, 'revision' => $created, 'expected_revision' => $current])->assertNotFound();

    expect($record->fresh()->name)->toBe('Second');
});

it('answers 409 when the record moved past the expected revision', function (): void {
    [$record, $created, $current] = revert_route_record();
    $record->update(['name' => 'Third']);

    revert_route_post($this, revert_route_actor(['select', 'revert']), ['id' => $record->id, 'revision' => $created, 'expected_revision' => $current])
        ->assertStatus(409);

    expect($record->fresh()->name)->toBe('Third');
});

it('answers 422 with the reason of a refused revert', function (): void {
    [$record, , $current] = revert_route_record();
    [, $other_created] = revert_route_record();

    $response = revert_route_post($this, revert_route_actor(['select', 'revert']), ['id' => $record->id, 'revision' => $other_created, 'expected_revision' => $current]);

    $response->assertStatus(422);
    expect((string) $response->getContent())->toContain('The revision is not one of this record.')
        ->and($response->json('data.reason'))->toBe('not-a-revision-of-this-record');
});

it('reverts a record in the bin, which stays there', function (): void {
    [$record, $created] = revert_route_record();
    $record->delete();
    $current = (int) User::withTrashed()->findOrFail($record->id)->currentRevision();

    revert_route_post($this, revert_route_actor(['select', 'revert']), ['id' => $record->id, 'revision' => $created, 'expected_revision' => $current])
        ->assertOk();

    $live = User::withTrashed()->findOrFail($record->id);

    expect($live->name)->toBe('First')
        ->and($live->trashed())->toBeTrue();
});

it('validates the revision, the expected revision and the mode', function (): void {
    [$record] = revert_route_record();

    revert_route_post($this, revert_route_actor(['select', 'revert']), ['id' => $record->id, 'mode' => 'everything'])
        ->assertStatus(422);
});

it('lets a later read see the reverted record, the response cache flushed', function (): void {
    [$record, $created, $current] = revert_route_record();
    $actor = revert_route_actor(['select', 'revert']);
    $detail = route('core.crud.detail', ['module' => 'core', 'entity' => 'users']) . '?' . http_build_query(['id' => $record->id]);

    expect($this->actingAs($actor)->getJson($detail)->json('data.name'))->toBe('Second');

    revert_route_post($this, $actor, ['id' => $record->id, 'revision' => $created, 'expected_revision' => $current])->assertOk();

    expect($this->actingAs($actor)->getJson($detail)->json('data.name'))->toBe('First');
});

it('governs the revert by revert, even where update is registered and not granted (D26)', function (): void {
    [$record, $created, $current] = revert_route_record();
    Permission::findOrCreate(PermissionName::forClass(User::class, 'update'), 'web');

    revert_route_post($this, revert_route_actor(['select', 'revert']), ['id' => $record->id, 'revision' => $created, 'expected_revision' => $current])
        ->assertOk();

    expect($record->fresh()->name)->toBe('First');
});

