<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Media;
use Modules\Core\Search\SearchableContributorRegistry;
use Modules\Core\Tests\Stubs\Search\MediaOwnerStubModel;
use Modules\Core\Tests\Stubs\Search\StubTranscriptMediaContributor;
use Modules\Core\Tests\Support\FakeVectorProfile;

beforeEach(function (): void {
    FakeVectorProfile::none();
    Schema::create('media_owner_stubs', function ($table): void {
        $table->id();
        $table->string('title')->nullable();
    });
});

function mediaOf(MediaOwnerStubModel $owner, array $custom): Media
{
    $media = new Media();
    $media->forceFill([
        'collection_name' => 'default',
        'name' => 'photo',
        'file_name' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'size' => 123,
        'model_type' => $owner->getMorphClass(),
        'model_id' => $owner->getKey(),
        'custom_properties' => $custom,
        'manipulations' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
    $media->saveQuietly();

    return $media;
}

it('carries its media compact surrogate, never the heavy tracks', function (): void {
    app(SearchableContributorRegistry::class)->register(new StubTranscriptMediaContributor());

    $owner = MediaOwnerStubModel::query()->create(['title' => 'Article']);
    mediaOf($owner, ['description' => 'Old lighthouse', 'keywords' => ['coast']]);

    $surrogate = $owner->fresh()->toSearchableArray()['media_surrogate'] ?? '';

    expect($surrogate)->toContain('Old lighthouse')
        ->and($surrogate)->toContain('coast')
        ->and($surrogate)->toContain('solitude')
        ->and($surrogate)->toContain('beacon')
        ->and($surrogate)->not->toContain('whispered transcript secret');
});

it('adds no surrogate for an owner without media', function (): void {
    $owner = MediaOwnerStubModel::query()->create(['title' => 'Bare']);

    expect($owner->toSearchableArray())->not->toHaveKey('media_surrogate');
});
