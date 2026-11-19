<?php

declare(strict_types=1);

use Modules\Core\Models\Media;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Search\OwnerAuthorizerRegistry;
use Modules\Core\Tests\Stubs\Search\StubOwnerAuthorizer;
use Modules\Core\Tests\Support\FakeVectorProfile;
use Symfony\Component\HttpFoundation\Response;

/**
 * The command palette asks `core/media` for the files by what they contain, with a POST: the route
 * is the generic `search/{module}/{entity?}` one, so these tests pin that a file entity answers on it. A file is
 * found by what it contains (the `description` and `keywords` of its surrogate), not by its name.
 */
beforeEach(function (): void {
    FakeVectorProfile::none();
});

function mediaSearchUrl(): string
{
    return route('core.crud.search', ['module' => 'core', 'entity' => 'media']);
}

function mediaSearchViewer(): User
{
    $viewer = User::factory()->create();
    $viewer->assignRole(Role::findOrCreate('superadmin', 'web'));

    return $viewer;
}

function searchableMedia(string $description, User $owner): Media
{
    $media = new Media;
    $media->forceFill([
        'collection_name' => 'default',
        'name' => 'scan',
        'file_name' => 'scan.pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 123,
        'model_type' => User::class,
        'model_id' => $owner->id,
        'custom_properties' => ['description' => $description],
        'manipulations' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
    $media->saveQuietly();

    return $media;
}

/**
 * @param  array<string, mixed>  $response
 * @return list<int>
 */
function mediaIdsOf(array $response): array
{
    return collect($response['data'] ?? [])->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
}

it('finds a file by POST on core/media, the way the palette asks', function (): void {
    config()->set('core.media.search_visibility', 'open');
    $owner = User::factory()->create();
    $match = searchableMedia('quarterlyinvoice', $owner);
    searchableMedia('holidayphoto', $owner);

    $response = $this->actingAs(mediaSearchViewer())
        ->postJson(mediaSearchUrl(), ['qs' => 'quarterlyinvoice', 'pagination' => 5, 'page' => 1]);

    $response->assertOk();

    expect($response->json('meta.class'))->toBe(Media::class)
        ->and(mediaIdsOf($response->json()))->toBe([$match->id]);
});

it('answers the same files to GET and POST', function (): void {
    config()->set('core.media.search_visibility', 'open');
    $match = searchableMedia('quarterlyinvoice', User::factory()->create());
    $viewer = mediaSearchViewer();

    $by_post = $this->actingAs($viewer)->postJson(mediaSearchUrl(), ['qs' => 'quarterlyinvoice']);
    $by_get = $this->actingAs($viewer)->getJson(mediaSearchUrl() . '?' . http_build_query(['qs' => 'quarterlyinvoice']));

    expect(mediaIdsOf($by_post->json()))->toBe([$match->id])
        ->and(mediaIdsOf($by_get->json()))->toBe([$match->id]);
});

it('returns nothing for a query no file matches', function (): void {
    config()->set('core.media.search_visibility', 'open');
    searchableMedia('holidayphoto', User::factory()->create());

    $response = $this->actingAs(mediaSearchViewer())->postJson(mediaSearchUrl(), ['qs' => 'zzzunmatched']);

    $response->assertOk();

    expect(mediaIdsOf($response->json()))->toBe([]);
});

it('does not return a file whose owner the user cannot see', function (): void {
    config()->set('core.media.search_visibility', 'owner');
    $visible = User::factory()->create();
    $hidden = User::factory()->create();
    app(OwnerAuthorizerRegistry::class)->register(new StubOwnerAuthorizer(User::class, [$visible->id]));
    $shown = searchableMedia('sharedinvoice', $visible);
    searchableMedia('sharedinvoice', $hidden);

    $response = $this->actingAs(mediaSearchViewer())->postJson(mediaSearchUrl(), ['qs' => 'sharedinvoice']);

    $response->assertOk();

    expect(mediaIdsOf($response->json()))->toBe([$shown->id]);
});

it('refuses the search without the select permission on media', function (): void {
    config()->set('core.media.search_visibility', 'open');
    searchableMedia('quarterlyinvoice', User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->postJson(mediaSearchUrl(), ['qs' => 'quarterlyinvoice'])
        ->assertStatus(Response::HTTP_FORBIDDEN);
});

it('requires a query', function (): void {
    $this->actingAs(mediaSearchViewer())
        ->postJson(mediaSearchUrl(), ['pagination' => 5])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
});
