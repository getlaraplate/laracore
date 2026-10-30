<?php

declare(strict_types=1);

use Modules\Core\Models\Media;
use Modules\Core\Models\MediaDraft;
use Modules\Core\Models\User;
use Modules\Core\Search\SearchableContributorRegistry;
use Modules\Core\Tests\Stubs\Search\StubMediaContributor;
use Modules\Core\Tests\Support\FakeVectorProfile;

function makeMedia(array $custom = [], ?string $ownerType = User::class): Media
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
        'model_id' => 1,
        'custom_properties' => $custom,
    ]);

    return $media;
}

beforeEach(function (): void {
    FakeVectorProfile::none();
});

it('is not searchable while owned by a media draft (claim gate)', function (): void {
    $draftOwned = makeMedia(ownerType: (new MediaDraft())->getMorphClass());

    expect($draftOwned->shouldBeSearchable())->toBeFalse();
});

it('is searchable once claimed onto a real owner', function (): void {
    expect(makeMedia()->shouldBeSearchable())->toBeTrue();
});

it('composes embeddable text from the custom_properties surrogate', function (): void {
    $media = makeMedia(['description' => 'A man smiling', 'keywords' => ['man', 'smile']]);

    expect($media->searchable_embed_text)->toBe('A man smiling man smile');
});

it('includes AI-contributed embeddable text through the seam', function (): void {
    app(SearchableContributorRegistry::class)->register(new StubMediaContributor(Media::class));

    $media = makeMedia(['description' => 'A man smiling']);

    expect($media->searchable_embed_text)->toBe('A man smiling freshness inform');
});

it('ships facets in the searchable document', function (): void {
    $media = makeMedia(['description' => 'A cat', 'keywords' => ['cat', 'pet']]);

    $document = $media->toSearchableArray();

    expect($document['mime'])->toBe('image/jpeg')
        ->and($document['track'])->toBe('media')
        ->and($document['keywords'])->toBe(['cat', 'pet'])
        ->and($document['description'])->toBe('A cat')
        ->and($document['entity'])->toBe($media->getTable());
});

it('exposes the embed field list', function (): void {
    expect(makeMedia()->getEmbedFields())->toBe(['searchable_embed_text']);
});
