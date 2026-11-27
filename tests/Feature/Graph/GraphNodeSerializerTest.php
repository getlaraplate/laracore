<?php

declare(strict_types=1);

use Modules\Core\Graph\GraphEntityResolver;
use Modules\Core\Graph\GraphNodeSerializer;
use Modules\Core\Graph\GraphProviderRegistry;
use Modules\Core\Models\User;

it('builds stable graph node ids from module entity and model key', function (): void {
    $user = new User();
    $user->forceFill(['id' => 7, 'name' => 'Graph User', 'email' => 'graph@example.test']);
    $user->exists = true;

    $serializer = new GraphNodeSerializer(new GraphEntityResolver(), new GraphProviderRegistry());

    $node = $serializer->serialize($user, 'summary');

    expect($node->id)->toBe('core:users:7');
    expect($node->module)->toBe('core');
    expect($node->entity)->toBe('users');
    expect($node->key)->toBe(7);
    expect($node->label)->toBe('Graph User');
    expect($node->attributes)->toHaveKey('name', 'Graph User');
    expect($node->attributes)->not->toHaveKey('password');
});

it('builds the label and the summary of a media from its projection for an anonymous api caller', function (): void {
    Illuminate\Support\Facades\Cache::flush();
    $anonymous = User::factory()->create(['name' => config('permission.users.guest'), 'username' => config('permission.users.guest')]);
    Illuminate\Support\Facades\Auth::shouldUse('api');
    Illuminate\Support\Facades\Auth::guard('api')->setUser($anonymous);

    $media = new Modules\Core\Models\Media();
    $media->forceFill(['id' => 11, 'name' => 'graphnode-original-basename', 'file_name' => 'graphnode-original-basename.jpg', 'collection_name' => 'images', 'mime_type' => 'image/jpeg', 'size' => 10, 'custom_properties' => []]);
    $media->exists = true;

    $node = (new GraphNodeSerializer(new GraphEntityResolver(), new GraphProviderRegistry()))->serialize($media, 'summary');

    expect($node->label)->toBe('11')
        ->and($node->attributes)->not->toHaveKeys(['name', 'file_name'])
        ->and(json_encode($node->attributes, JSON_THROW_ON_ERROR))->not->toContain('graphnode-original-basename');
});

it('never builds the label or the summary from an attribute the model hides', function (): void {
    $user = new User();
    $user->forceFill(['id' => 8, 'name' => 'graphnode-hidden-name', 'email' => 'graph-hidden@example.test']);
    $user->exists = true;
    $user->setHidden(['name', 'password']);

    $node = (new GraphNodeSerializer(new GraphEntityResolver(), new GraphProviderRegistry()))->serialize($user, 'summary');

    expect($node->label)->toBe('8')
        ->and($node->attributes)->not->toHaveKey('name');
});

it('never puts a hierarchy path on a graph node', function (string $detail): void {
    $user = new User();
    $user->forceFill(['id' => 9, 'name' => 'Graph Path User', 'slug' => 'graph-path-user', 'path' => 'users/root/child/graph-path-user/9']);
    $user->exists = true;

    $node = (new GraphNodeSerializer(new GraphEntityResolver(), new GraphProviderRegistry()))->serialize($user, $detail);

    expect($node->attributes)->not->toHaveKey('path');
})->with(['minimal', 'summary']);
