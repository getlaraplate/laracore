<?php

declare(strict_types=1);

use Modules\Core\Filament\RelationManagers\MediaRelationManager;
use Modules\Core\Models\Media;
use Modules\Core\Models\User;
use Modules\Core\Tests\Support\FakeVectorProfile;

function relationManagerMedia(array $custom): Media
{
    $media = new Media();
    $media->forceFill([
        'collection_name' => 'default',
        'name' => 'photo',
        'file_name' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'size' => 1024,
        'model_type' => User::class,
        'model_id' => 1,
        'custom_properties' => $custom,
        'manipulations' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
    $media->saveQuietly();

    return $media;
}

beforeEach(function (): void {
    FakeVectorProfile::none();
});

it('merges display edits into custom_properties and marks them human, preserving other keys', function (): void {
    $media = relationManagerMedia([
        'content_hash' => 'h1',
        'technical' => ['width' => 800, 'height' => 600],
        'description' => 'ai caption',
        '_provenance' => ['description' => 'llm'],
    ]);

    MediaRelationManager::applyDisplayEdit($media, [
        'description' => 'a curated caption',
        'alt_text' => 'a lighthouse',
        'keywords' => ['lighthouse', 'sea', ''],
    ]);

    $custom = $media->fresh()->custom_properties;

    expect($custom['content_hash'])->toBe('h1')
        ->and($custom['technical'])->toBe(['width' => 800, 'height' => 600])
        ->and($custom['description'])->toBe('a curated caption')
        ->and($custom['alt_text'])->toBe('a lighthouse')
        ->and($custom['keywords'])->toBe(['lighthouse', 'sea'])
        ->and($custom['_provenance'])->toBe([
            'description' => 'human',
            'alt_text' => 'human',
            'keywords' => 'human',
        ]);
});

it('clears a display field and its provenance when emptied', function (): void {
    $media = relationManagerMedia([
        'content_hash' => 'h2',
        'description' => 'to be cleared',
        'keywords' => ['old'],
        '_provenance' => ['description' => 'human', 'keywords' => 'human'],
    ]);

    MediaRelationManager::applyDisplayEdit($media, [
        'description' => '',
        'alt_text' => null,
        'keywords' => [],
    ]);

    $custom = $media->fresh()->custom_properties;

    expect($custom)->not->toHaveKey('description')
        ->and($custom)->not->toHaveKey('keywords')
        ->and($custom['content_hash'])->toBe('h2')
        ->and($custom['_provenance'])->toBe([]);
});
