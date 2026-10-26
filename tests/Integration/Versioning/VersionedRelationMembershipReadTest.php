<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Version;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationRoot;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationSubject;

beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);

    Schema::create(VersionedRelationRoot::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });
    Schema::create(VersionedRelationSubject::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create(VersionedRelationRoot::PIVOT_TABLE, function (Blueprint $table): void {
        $table->unsignedBigInteger('root_id');
        $table->unsignedBigInteger('subject_id');
        $table->unsignedInteger('position')->default(0);
    });
});

afterEach(function (): void {
    Schema::dropIfExists(VersionedRelationRoot::PIVOT_TABLE);
    Schema::dropIfExists(VersionedRelationSubject::TABLE);
    Schema::dropIfExists(VersionedRelationRoot::TABLE);
});

it('reconstructs membership by replaying attach and detach events', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $a = VersionedRelationSubject::query()->create(['name' => 'A']);
    $b = VersionedRelationSubject::query()->create(['name' => 'B']);

    $root->categories()->attach($a->getKey(), ['position' => 1]);
    $root->categories()->attach($b->getKey(), ['position' => 2]);
    $root->categories()->detach($a->getKey());

    $membership = $root->versionedRelationMembership('categories');

    expect($membership)->toHaveCount(1)
        ->and($membership[0]['id'])->toBe($b->getKey())
        ->and($membership[0]['pivot'])->toBe(['position' => 2]);
});
