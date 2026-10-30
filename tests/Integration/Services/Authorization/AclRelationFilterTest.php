<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Casts\CrudExecutor;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\FiltersGroupCast;
use Modules\Core\Casts\RelationFilter;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Media;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Overrides\ContextualValidationException;
use Modules\Core\Rules\QueryBuilder as QueryBuilderRule;
use Modules\Core\Search\Services\ScoutSearchConstraintApplier;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Stubs\Search\RecordingEngineBuilderStub;
use Modules\Core\Tests\Support\FakeVectorProfile;

beforeEach(function (): void {
    Cache::flush();
    Carbon::setTestNow('2026-08-19 12:00:00');
    config()->set('permission.roles.superadmin', 'superadmin');
    FakeVectorProfile::none();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A media row owned by the given model, as a Spatie media owned by a `HasMedia` model.
 */
function aclrel_media(string $owner_type, int|string $owner_id): Media
{
    $media = new Media;
    $media->forceFill([
        'collection_name' => 'default',
        'name' => 'photo',
        'file_name' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'size' => 123,
        'model_type' => $owner_type,
        'model_id' => $owner_id,
        'custom_properties' => [],
        'manipulations' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
    $media->saveQuietly();

    return $media;
}

/**
 * The owner condition of a media: its owner is a user created at or before the moment of the request.
 */
function aclrel_owner_filters(string $name): FiltersGroup
{
    return new FiltersGroup(
        filters: [
            new RelationFilter(
                relation: 'model',
                filters: new FiltersGroup(
                    filters: [
                        new Filter('name', $name, FilterOperator::Equals),
                        new Filter('created_at', '@now', FilterOperator::LessEquals),
                    ],
                    operator: WhereClause::And,
                ),
                morph_types: [User::class],
            ),
        ],
        operator: WhereClause::And,
    );
}

/**
 * A role on the `api` guard holding `vend_media.select`, narrowed by an ACL.
 *
 * @return array{0: App\Models\User, 1: string}
 */
function aclrel_media_reader(FiltersGroup $filters): array
{
    $permission_name = PermissionName::forClass(Media::class, 'select');
    Permission::query()->firstOrCreate(['name' => $permission_name, 'guard_name' => 'web']);
    $permission = Permission::query()->firstOrCreate(['name' => $permission_name, 'guard_name' => 'api']);

    $role = Role::factory()->create(['name' => 'aclrel_reader_' . uniqid(), 'guard_name' => 'api']);
    $role->givePermissionTo($permission);

    $acl = new ACL;
    $acl->setSkipValidation(true);
    $acl->forceFill([
        'permission_id' => $permission->id,
        'role_id' => $role->id,
        'filters' => $filters,
        'unrestricted' => false,
        'priority' => 10,
        'is_active' => true,
    ]);
    $acl->save();

    $user = App\Models\User::query()->findOrFail(User::factory()->create()->getKey());
    $user->assignRole($role);

    return [$user->fresh(), $permission_name];
}

it('round-trips a relation filter through the filters column of an ACL', function (): void {
    $permission = Permission::query()->create(['name' => 'default.vend_media.select', 'guard_name' => 'api']);
    $filters = aclrel_owner_filters('owner');

    $acl = new ACL;
    $acl->setSkipValidation(true);
    $acl->forceFill([
        'permission_id' => $permission->id,
        'filters' => $filters,
        'unrestricted' => false,
        'priority' => 10,
        'is_active' => true,
    ]);
    $acl->save();

    $stored = $acl->fresh()->filters;
    $relation = $stored->filters[0];

    expect($relation)->toBeInstanceOf(RelationFilter::class)
        ->and($relation->relation)->toBe('model')
        ->and($relation->morph_types)->toBe([User::class])
        ->and($relation->filters->filters)->toHaveCount(2)
        ->and($relation->filters->filters[1]->value)->toBe('@now')
        ->and($stored->toArray())->toBe($filters->toArray());

    $raw = json_decode((string) $acl->fresh()->getRawOriginal('filters'), true);

    expect($raw['filters'][0])->toHaveKeys(['relation', 'morph_types', 'filters'])
        ->and($raw['filters'][0]['relation'])->toBe('model');
});

it('hydrates a relation filter from its JSON and leaves morph_types unset for a plain relation', function (): void {
    $cast = new FiltersGroupCast;
    $hydrated = $cast->get(new ACL, 'filters', json_encode([
        'operator' => 'and',
        'filters' => [
            ['relation' => 'content', 'filters' => ['operator' => 'and', 'filters' => [['property' => 'id', 'operator' => '=', 'value' => 1]]]],
        ],
    ]), []);

    $relation = $hydrated->filters[0];

    expect($relation)->toBeInstanceOf(RelationFilter::class)
        ->and($relation->morph_types)->toBeNull()
        ->and($relation->filters->filters[0])->toBeInstanceOf(Filter::class)
        ->and($relation->toArray())->not->toHaveKey('morph_types');
});

it('accepts a relation filter in the ACL validation rule and refuses a malformed one', function (): void {
    $failures = [];
    $fail = static function (string $message) use (&$failures): void {
        $failures[] = $message;
    };

    (new QueryBuilderRule)->validate('filters', aclrel_owner_filters('owner')->toArray(), $fail);
    expect($failures)->toBe([]);

    (new QueryBuilderRule)->validate('filters', ['filters' => [['relation' => 'model']], 'operator' => 'and'], $fail);
    expect($failures)->not->toBe([]);
});

/**
 * An ACL on the `select` permission of a model (Media by default), not yet saved, ready to be validated as a save would.
 *
 * @param  array<string, mixed>|FiltersGroup  $filters
 * @param  class-string<Illuminate\Database\Eloquent\Model>  $model_class
 */
function aclrel_unsaved_media_acl(array|FiltersGroup $filters, string $model_class = Media::class): ACL
{
    $permission = Permission::query()->firstOrCreate([
        'name' => PermissionName::forClass($model_class, 'select'),
        'guard_name' => 'api',
    ]);

    $acl = new ACL;
    $acl->forceFill([
        'permission_id' => $permission->id,
        'filters' => $filters,
        'unrestricted' => false,
        'priority' => 10,
        'is_active' => true,
    ]);

    return $acl;
}

it('accepts at save a relation filter that names a real relation and a real morph type', function (): void {
    $acl = aclrel_unsaved_media_acl(aclrel_owner_filters('owner'));

    expect(fn () => $acl->validateWithRules(CrudExecutor::INSERT))->not->toThrow(ContextualValidationException::class);
});

it('rejects at save a relation filter whose relation does not exist on the entity', function (): void {
    $acl = aclrel_unsaved_media_acl(new FiltersGroup([
        new RelationFilter('nowhere', new FiltersGroup([new Filter('id', 1, FilterOperator::Equals)])),
    ]));

    expect(fn () => $acl->validateWithRules(CrudExecutor::INSERT))->toThrow(ContextualValidationException::class);
});

it('rejects at save a relation filter that names a method which is not a relation', function (): void {
    $acl = aclrel_unsaved_media_acl(new FiltersGroup([
        new RelationFilter('delete', new FiltersGroup([new Filter('id', 1, FilterOperator::Equals)])),
    ]));

    expect(fn () => $acl->validateWithRules(CrudExecutor::INSERT))->toThrow(ContextualValidationException::class);
});

it('rejects at save a morph relation without morph types and a plain relation with morph types', function (): void {
    $nested = new FiltersGroup([new Filter('id', 1, FilterOperator::Equals)]);

    $morph_without_types = aclrel_unsaved_media_acl(new FiltersGroup([new RelationFilter('model', $nested)]));
    $plain_with_types = aclrel_unsaved_media_acl(new FiltersGroup([
        new RelationFilter('roles', $nested, [Role::class]),
    ]), User::class);

    expect(fn () => $morph_without_types->validateWithRules(CrudExecutor::INSERT))->toThrow(ContextualValidationException::class);
    expect(fn () => $plain_with_types->validateWithRules(CrudExecutor::INSERT))->toThrow(ContextualValidationException::class);
});

it('rejects at save a morph type that is not a model class, from the JSON array form too', function (): void {
    $nested = ['filters' => [['property' => 'id', 'operator' => '=', 'value' => 1]], 'operator' => 'and'];

    $unknown_class = aclrel_unsaved_media_acl(new FiltersGroup([
        new RelationFilter('model', new FiltersGroup([new Filter('id', 1, FilterOperator::Equals)]), ['Modules\\Nowhere\\Models\\Ghost']),
    ]));
    $not_a_model = aclrel_unsaved_media_acl(['operator' => 'and', 'filters' => [
        ['relation' => 'model', 'morph_types' => [stdClass::class], 'filters' => $nested],
    ]]);

    expect(fn () => $unknown_class->validateWithRules(CrudExecutor::INSERT))->toThrow(ContextualValidationException::class);
    expect(fn () => $not_a_model->validateWithRules(CrudExecutor::INSERT))->toThrow(ContextualValidationException::class);
});

it('rejects at save a nested relation filter that is wrong for the related entity', function (): void {
    $acl = aclrel_unsaved_media_acl(new FiltersGroup([
        new RelationFilter(
            'model',
            new FiltersGroup([new RelationFilter('nowhere', new FiltersGroup([new Filter('id', 1, FilterOperator::Equals)]))]),
            [User::class],
        ),
    ]));

    expect(fn () => $acl->validateWithRules(CrudExecutor::INSERT))->toThrow(ContextualValidationException::class);
});

it('fails closed on a relation filter whose nested conditions are a bare list, on read and on write', function (): void {
    $cast = new FiltersGroupCast;
    $list_form = [
        'operator' => 'and',
        'filters' => [[
            'relation' => 'model',
            'morph_types' => [User::class],
            'filters' => [['property' => 'name', 'operator' => '=', 'value' => 'kept-owner']],
        ]],
    ];

    expect(fn () => $cast->get(new ACL, 'filters', json_encode($list_form), []))->toThrow(InvalidArgumentException::class);
    expect(fn () => $cast->set(new ACL, 'filters', $list_form, []))->toThrow(InvalidArgumentException::class);
});

it('fails closed on a relation filter with an empty nested group or an empty morph type list', function (): void {
    $cast = new FiltersGroupCast;
    $group = ['operator' => 'and', 'filters' => [['property' => 'name', 'operator' => '=', 'value' => 'x']]];

    $empty_nested = ['relation' => 'model', 'morph_types' => [User::class], 'filters' => []];
    $empty_types = ['relation' => 'model', 'morph_types' => [], 'filters' => $group];

    expect(fn () => $cast->get(new ACL, 'filters', json_encode(['operator' => 'and', 'filters' => [$empty_nested]]), []))->toThrow(InvalidArgumentException::class);
    expect(fn () => $cast->get(new ACL, 'filters', json_encode(['operator' => 'and', 'filters' => [$empty_types]]), []))->toThrow(InvalidArgumentException::class);
});

it('throws on hydration, and rejects at save, a filters list that holds an item which is not a filter, a group or a relation node', function (array $filters): void {
    $json = json_encode(['operator' => 'and', 'filters' => $filters]);

    expect(fn () => (new FiltersGroupCast)->get(new ACL, 'filters', $json, []))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new FiltersGroupCast)->set(new ACL, 'filters', json_decode($json, true), []))->toThrow(InvalidArgumentException::class);

    $acl = aclrel_unsaved_media_acl(new FiltersGroup);
    $acl->setRawAttributes(array_merge($acl->getAttributes(), ['filters' => $json]));

    expect(fn () => $acl->validateWithRules(CrudExecutor::INSERT))->toThrow(ContextualValidationException::class);
})->with([
    'a string' => [[['property' => 'collection_name', 'operator' => '=', 'value' => 'cover'], 'oops']],
    'a null' => [[['property' => 'collection_name', 'operator' => '=', 'value' => 'cover'], null]],
    'an unknown node' => [[['property' => 'collection_name', 'operator' => '=', 'value' => 'cover'], ['foo' => 'bar']]],
    'a bare list' => [[['property' => 'collection_name', 'operator' => '=', 'value' => 'cover'], [['property' => 'id', 'operator' => '=', 'value' => 1]]]],
    'a nested group with a bad item' => [[['filters' => [['property' => 'id', 'operator' => '=', 'value' => 1], 5], 'operator' => 'or']]],
]);

it('throws on hydration of a filter whose operator is unknown instead of falling back to equality', function (): void {
    $json = json_encode(['operator' => 'and', 'filters' => [['property' => 'id', 'operator' => 'nonsense', 'value' => 1]]]);

    expect(fn () => (new FiltersGroupCast)->get(new ACL, 'filters', $json, []))->toThrow(InvalidArgumentException::class);

    $acl = aclrel_unsaved_media_acl(new FiltersGroup);
    $acl->setRawAttributes(array_merge($acl->getAttributes(), ['filters' => $json]));

    expect(fn () => $acl->validateWithRules(CrudExecutor::INSERT))->toThrow(ContextualValidationException::class);
});

it('fails closed on a relation filter whose nested group would constrain nothing, at hydration and at save', function (array $relation_node): void {
    $json = json_encode(['operator' => 'and', 'filters' => [$relation_node]]);

    expect(fn () => (new FiltersGroupCast)->get(new ACL, 'filters', $json, []))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new FiltersGroupCast)->set(new ACL, 'filters', json_decode($json, true), []))->toThrow(InvalidArgumentException::class);

    $acl = aclrel_unsaved_media_acl(new FiltersGroup);
    $acl->setRawAttributes(array_merge($acl->getAttributes(), ['filters' => $json]));

    expect(fn () => $acl->validateWithRules(CrudExecutor::INSERT))->toThrow(ContextualValidationException::class);
})->with([
    'numeric keys not starting at zero' => [['relation' => 'model', 'morph_types' => [User::class], 'filters' => ['1' => ['property' => 'name', 'operator' => '=', 'value' => 'x']]]],
    'an explicit empty group' => [['relation' => 'model', 'morph_types' => [User::class], 'filters' => ['operator' => 'and', 'filters' => []]]],
    'a group holding only an empty group' => [['relation' => 'model', 'morph_types' => [User::class], 'filters' => ['operator' => 'and', 'filters' => [['operator' => 'and', 'filters' => []]]]]],
]);

it('throws on a stored filters column that cannot be read, and keeps null and empty meaning no filters', function (mixed $stored): void {
    expect(fn () => (new FiltersGroupCast)->get(new ACL, 'filters', $stored, []))->toThrow(InvalidArgumentException::class);
})->with([
    'broken json' => ['{invalid'],
    'a json string' => ['"everything"'],
    'a json number' => ['5'],
    'a json boolean' => ['true'],
    'a non-json scalar' => [123],
]);

it('reads a null or empty filters column as no filters', function (): void {
    expect((new FiltersGroupCast)->get(new ACL, 'filters', null, []))->toBeNull()
        ->and((new FiltersGroupCast)->get(new ACL, 'filters', '', []))->toBeNull();
});

it('does not grant unrestricted access when the stored filters of an ACL are corrupted', function (): void {
    CrudApiExposure::enable();
    $owner = User::factory()->create(['name' => 'kept-owner']);
    aclrel_media($owner->getMorphClass(), $owner->getKey());
    [$reader] = aclrel_media_reader(aclrel_owner_filters('kept-owner'));
    ACL::query()->update(['filters' => '{corrupted']);
    Cache::flush();

    $response = $this->actingAs($reader)->getJson('/api/v1/select/core/media');

    expect($response->status())->toBeGreaterThanOrEqual(400);
});

it('throws an InvalidArgumentException, not a conversion error, for an array where a name or operator is expected', function (array $payload): void {
    expect(fn () => (new FiltersGroupCast)->get(new ACL, 'filters', json_encode($payload), []))->toThrow(InvalidArgumentException::class);
})->with([
    'group operator' => [['operator' => ['and'], 'filters' => [['property' => 'id', 'operator' => '=', 'value' => 1]]]],
    'filter property' => [['operator' => 'and', 'filters' => [['property' => ['id'], 'operator' => '=', 'value' => 1]]]],
    'filter operator' => [['operator' => 'and', 'filters' => [['property' => 'id', 'operator' => ['='], 'value' => 1]]]],
]);

/**
 * Stored filters that drop a condition when read loosely: an extra key, keys of two node kinds at once, or a
 * group that constrains nothing.
 *
 * @return array<string, array{0: array<string, mixed>}>
 */
function aclrel_ambiguous_filters(): array
{
    $a = ['property' => 'name', 'operator' => '=', 'value' => 'a'];
    $b = ['property' => 'name', 'operator' => '=', 'value' => 'b'];

    return [
        'relation nested group with a numeric extra key' => [['operator' => 'and', 'filters' => [['relation' => 'model', 'morph_types' => [User::class], 'filters' => ['operator' => 'and', 'filters' => [$a], '0' => $b]]]]],
        'relation nested filter with a numeric extra key' => [['operator' => 'and', 'filters' => [['relation' => 'model', 'morph_types' => [User::class], 'filters' => $a + ['1' => $b]]]]],
        'a node with property and filters' => [['operator' => 'and', 'filters' => [$a + ['filters' => [$b]]]]],
        'a group with an unknown key' => [['operator' => 'and', 'filters' => [$a], 'note' => 'x']],
        'a filter with an unknown key' => [['operator' => 'and', 'filters' => [$a + ['note' => 'x']]]],
        'a relation node with an unknown key' => [['operator' => 'and', 'filters' => [['relation' => 'model', 'morph_types' => [User::class], 'filters' => ['operator' => 'and', 'filters' => [$a]], 'note' => 'x']]]],
        'a relation node with a property' => [['operator' => 'and', 'filters' => [['relation' => 'model', 'morph_types' => [User::class], 'filters' => ['operator' => 'and', 'filters' => [$a]], 'property' => 'id']]]],
        'a top-level group with a hidden extra condition' => [['filters' => [], '0' => $b]],
        'a top-level group of empty groups' => [['filters' => [['filters' => []]]]],
        'an empty top-level group' => [['operator' => 'and', 'filters' => []]],
    ];
}

it('refuses to read a stored node with a key outside its kind or with keys of two kinds, and a group that constrains nothing', function (array $stored): void {
    expect(fn () => (new FiltersGroupCast)->get(new ACL, 'filters', json_encode($stored), []))->toThrow(InvalidArgumentException::class);
})->with(aclrel_ambiguous_filters());

it('rejects at save a stored node with a key outside its kind or with keys of two kinds, and a group that constrains nothing', function (array $stored): void {
    $acl = aclrel_unsaved_media_acl(new FiltersGroup);
    $acl->setRawAttributes(array_merge($acl->getAttributes(), ['filters' => json_encode($stored)]));

    expect(fn () => $acl->validateWithRules(CrudExecutor::INSERT))->toThrow(ContextualValidationException::class);
})->with(aclrel_ambiguous_filters());

it('refuses to store a node with a key outside its kind', function (array $stored): void {
    expect(fn () => (new FiltersGroupCast)->set(new ACL, 'filters', $stored, []))->toThrow(InvalidArgumentException::class);
})->with(array_slice(aclrel_ambiguous_filters(), 0, 7, true));

it('does not grant access, and answers with a client error, when a stored top-level group is ambiguous or constrains nothing', function (array $stored): void {
    CrudApiExposure::enable();
    $owner = User::factory()->create(['name' => 'kept-owner']);
    aclrel_media($owner->getMorphClass(), $owner->getKey());
    aclrel_media($owner->getMorphClass(), $owner->getKey());
    [$reader] = aclrel_media_reader(aclrel_owner_filters('kept-owner'));
    ACL::query()->update(['filters' => json_encode($stored)]);
    Cache::flush();

    $response = $this->actingAs($reader)->getJson('/api/v1/select/core/media');

    expect($response->status())->toBe(400)
        ->and($response->json('data'))->toBe([]);
})->with(array_slice(aclrel_ambiguous_filters(), 7, null, true));

it('keeps reading an unrestricted ACL whose stored group is empty', function (): void {
    $acl = new ACL;

    expect((new FiltersGroupCast)->get($acl, 'filters', json_encode(['operator' => 'and', 'filters' => []]), ['unrestricted' => true]))
        ->toBeInstanceOf(FiltersGroup::class);
});

it('never calls a method that is not a relation when a relation filter is applied, even if the ACL skipped validation', function (): void {
    CrudApiExposure::enable();
    $owner = User::factory()->create(['name' => 'kept-owner']);
    aclrel_media($owner->getMorphClass(), $owner->getKey());
    $filters = new FiltersGroup([
        new RelationFilter('truncate', new FiltersGroup([new Filter('id', 1, FilterOperator::GreatEquals)])),
    ]);
    [$reader, $permission_name] = aclrel_media_reader($filters);

    $response = $this->actingAs($reader)->getJson('/api/v1/select/core/media');

    expect($response->status())->toBeGreaterThanOrEqual(400)
        ->and(Media::query()->withoutGlobalScopes()->count())->toBe(1);

    Auth::shouldUse('api');
    Auth::guard('api')->setUser($reader);

    expect(fn () => app(AuthorizationService::class)->applyAclFiltersToQuery(Media::query(), $permission_name))->toThrow(InvalidArgumentException::class);
    expect(Media::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('refuses at query time a morph wildcard, an unknown morph type and a plain relation given morph types', function (array $relation_filter): void {
    CrudApiExposure::enable();
    $owner = User::factory()->create(['name' => 'kept-owner']);
    aclrel_media($owner->getMorphClass(), $owner->getKey());
    [$reader, $permission_name] = aclrel_media_reader(new FiltersGroup([
        new RelationFilter(...$relation_filter),
    ]));

    $response = $this->actingAs($reader)->getJson('/api/v1/select/core/media');

    expect($response->status())->toBeGreaterThanOrEqual(400);

    Auth::shouldUse('api');
    Auth::guard('api')->setUser($reader);

    expect(fn () => app(AuthorizationService::class)->applyAclFiltersToQuery(Media::query(), $permission_name))->toThrow(InvalidArgumentException::class);
})->with([
    'wildcard' => [fn () => ['relation' => 'model', 'filters' => new FiltersGroup([new Filter('id', 1, FilterOperator::GreatEquals)]), 'morph_types' => ['*']]],
    'unknown class' => [fn () => ['relation' => 'model', 'filters' => new FiltersGroup([new Filter('id', 1, FilterOperator::GreatEquals)]), 'morph_types' => ['Modules\\Nowhere\\Models\\Ghost']]],
    'morph relation without types' => [fn () => ['relation' => 'model', 'filters' => new FiltersGroup([new Filter('id', 1, FilterOperator::GreatEquals)])]],
]);

it('refuses a relation filter that names no morph type when it is given an empty list', function (): void {
    expect(fn () => new RelationFilter('model', new FiltersGroup, []))->toThrow(InvalidArgumentException::class);
});

it('keeps on a list only the media whose morph owner matches the nested filters, resolving @now', function (): void {
    CrudApiExposure::enable();
    $kept_owner = User::factory()->create(['name' => 'kept-owner']);
    $other_owner = User::factory()->create(['name' => 'other-owner']);
    $future_owner = User::factory()->create(['name' => 'kept-owner']);
    $future_owner->forceFill(['created_at' => now()->addWeek()])->saveQuietly();

    $kept = aclrel_media($kept_owner->getMorphClass(), $kept_owner->getKey());
    aclrel_media($other_owner->getMorphClass(), $other_owner->getKey());
    aclrel_media($future_owner->getMorphClass(), $future_owner->getKey());
    aclrel_media('Modules\\Nowhere\\Models\\Ghost', 1);

    [$reader] = aclrel_media_reader(aclrel_owner_filters('kept-owner'));

    $response = $this->actingAs($reader)->getJson('/api/v1/select/core/media');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$kept->id]);
});

it('applies the relation filter on a detail too: the record behind a matching owner is returned, any other is not found', function (): void {
    CrudApiExposure::enable();
    $kept_owner = User::factory()->create(['name' => 'kept-owner']);
    $other_owner = User::factory()->create(['name' => 'other-owner']);
    $kept = aclrel_media($kept_owner->getMorphClass(), $kept_owner->getKey());
    $hidden = aclrel_media($other_owner->getMorphClass(), $other_owner->getKey());

    [$reader] = aclrel_media_reader(aclrel_owner_filters('kept-owner'));

    $this->actingAs($reader)->getJson('/api/v1/detail/core/media?id=' . $kept->id)
        ->assertOk()
        ->assertJsonPath('data.id', $kept->id);
    $this->actingAs($reader)->getJson('/api/v1/detail/core/media?id=' . $hidden->id)
        ->assertNotFound();
});

it('applies the same relation filter when the ACL is applied straight to a query', function (): void {
    $kept_owner = User::factory()->create(['name' => 'kept-owner']);
    $other_owner = User::factory()->create(['name' => 'other-owner']);
    $kept = aclrel_media($kept_owner->getMorphClass(), $kept_owner->getKey());
    aclrel_media($other_owner->getMorphClass(), $other_owner->getKey());

    [$reader, $permission_name] = aclrel_media_reader(aclrel_owner_filters('kept-owner'));
    Auth::shouldUse('api');
    Auth::guard('api')->setUser($reader);

    $query = Media::query();
    app(AuthorizationService::class)->applyAclFiltersToQuery($query, $permission_name);

    expect($query->pluck('id')->all())->toBe([$kept->id]);
});

it('combines a relation filter with a column filter in an OR group', function (): void {
    $owner = User::factory()->create(['name' => 'kept-owner']);
    $other = User::factory()->create(['name' => 'other-owner']);
    $by_owner = aclrel_media($owner->getMorphClass(), $owner->getKey());
    $by_column = aclrel_media($other->getMorphClass(), $other->getKey());
    $by_column->forceFill(['collection_name' => 'covers'])->saveQuietly();
    $neither = aclrel_media($other->getMorphClass(), $other->getKey());

    $filters = new FiltersGroup(
        filters: [
            aclrel_owner_filters('kept-owner'),
            new FiltersGroup([new Filter('collection_name', 'covers', FilterOperator::Equals)]),
        ],
        operator: WhereClause::Or,
    );
    [$reader, $permission_name] = aclrel_media_reader($filters);
    Auth::shouldUse('api');
    Auth::guard('api')->setUser($reader);

    $query = Media::query()->orderBy('id');
    app(AuthorizationService::class)->applyAclFiltersToQuery($query, $permission_name);

    expect($query->pluck('id')->all())->toBe([$by_owner->id, $by_column->id])
        ->and($query->pluck('id')->all())->not->toContain($neither->id);
});

it('does not push a relation filter to the search engine and keeps the column conditions beside it', function (): void {
    $builder = new RecordingEngineBuilderStub;
    $filters = new FiltersGroup(
        filters: [
            new Filter('collection_name', 'covers', FilterOperator::Equals),
            aclrel_owner_filters('kept-owner'),
        ],
        operator: WhereClause::And,
    );

    app(ScoutSearchConstraintApplier::class)->apply($builder, new Media, $filters);

    expect($builder->calls)->toBe([['method' => 'where', 'field' => 'collection_name', 'value' => 'covers']])
        ->and($builder->options)->toBe([]);
});

it('pushes nothing for an OR group that holds a relation filter, so the engine never drops what the relation allows', function (): void {
    $builder = new RecordingEngineBuilderStub;
    $filters = new FiltersGroup(
        filters: [
            new Filter('collection_name', 'covers', FilterOperator::Equals),
            aclrel_owner_filters('kept-owner'),
        ],
        operator: WhereClause::Or,
    );

    app(ScoutSearchConstraintApplier::class)->apply($builder, new Media, $filters);

    expect($builder->calls)->toBe([])
        ->and($builder->options)->toBe([]);
});

it('drops, when the hits are reloaded, the hits whose related record does not match', function (): void {
    $kept_owner = User::factory()->create(['name' => 'kept-owner']);
    $other_owner = User::factory()->create(['name' => 'other-owner']);
    $kept = aclrel_media($kept_owner->getMorphClass(), $kept_owner->getKey());
    $dropped = aclrel_media($other_owner->getMorphClass(), $other_owner->getKey());
    config()->set('core.media.search_visibility', 'open');

    [$reader, $permission_name] = aclrel_media_reader(aclrel_owner_filters('kept-owner'));
    Auth::shouldUse('api');
    Auth::guard('api')->setUser($reader);

    $hits_query = new ReflectionMethod(CrudService::class, 'searchHitsQuery');
    $query = $hits_query->invoke(app(CrudService::class), new Media, [$dropped->id, $kept->id], $permission_name);

    expect($query->pluck('id')->all())->toBe([$kept->id]);
});
