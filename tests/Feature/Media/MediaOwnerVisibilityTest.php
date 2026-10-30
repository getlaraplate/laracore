<?php

declare(strict_types=1);

use Modules\Core\Models\Media;
use Modules\Core\Models\User;
use Modules\Core\Search\OwnerAuthorizerRegistry;
use Modules\Core\Tests\Stubs\Search\StubOwnerAuthorizer;
use Modules\Core\Tests\Support\FakeVectorProfile;

/**
 * A media row owned by a User (any model class works as an owner), sharing one content hash.
 */
function ownedMedia(string $ownerType, int $ownerId): Media
{
    $media = new Media();
    $media->forceFill([
        'collection_name' => 'default',
        'name' => 'photo',
        'file_name' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'size' => 123,
        'model_type' => $ownerType,
        'model_id' => $ownerId,
        'custom_properties' => ['content_hash' => 'same-file'],
        'manipulations' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
    $media->saveQuietly();

    return $media;
}

/**
 * @param  list<int>  $ids
 * @return list<int>
 */
function rehydratedMediaIds(array $ids): array
{
    $media = new Media();

    return $media->authorizeSearchRehydration(Media::query()->whereKey($ids))
        ->pluck('id')
        ->sort()
        ->values()
        ->all();
}

beforeEach(function (): void {
    FakeVectorProfile::none();
});

it('drops a media whose owner the user cannot see, keeping its duplicate under a visible owner', function (): void {
    config()->set('core.media.search_visibility', 'owner');
    $visible = User::factory()->create();
    $hidden = User::factory()->create();
    app(OwnerAuthorizerRegistry::class)->register(new StubOwnerAuthorizer(User::class, [$visible->id]));

    $kept = ownedMedia(User::class, $visible->id);
    $dropped = ownedMedia(User::class, $hidden->id);

    expect(rehydratedMediaIds([$kept->id, $dropped->id]))->toBe([$kept->id]);
});

it('returns every media in open mode', function (): void {
    config()->set('core.media.search_visibility', 'open');
    $visible = User::factory()->create();
    $hidden = User::factory()->create();
    app(OwnerAuthorizerRegistry::class)->register(new StubOwnerAuthorizer(User::class, [$visible->id]));

    $first = ownedMedia(User::class, $visible->id);
    $second = ownedMedia(User::class, $hidden->id);

    expect(rehydratedMediaIds([$first->id, $second->id]))->toBe([$first->id, $second->id]);
});

it('drops a media whose owner type has no authorizer and no evaluable permission', function (): void {
    config()->set('core.media.search_visibility', 'owner');
    $this->actingAs(User::factory()->create());

    $orphan = ownedMedia('Modules\\Nowhere\\Models\\Ghost', 1);

    expect(rehydratedMediaIds([$orphan->id]))->toBe([]);
});
