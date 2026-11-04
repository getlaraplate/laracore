<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Locking\Exceptions\StaleModelLockingException;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\OptimisticVersionSetArticle;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;
use Modules\Core\Versioning\Data\VersionSetRoot;

/**
 * When a version set locks its root, the instance keeps the optimistic lock version it carries, so a stale one is
 * refused instead of being silently refreshed (D34).
 */
beforeEach(function (): void {
    Schema::create(OptimisticVersionSetArticle::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->unsignedInteger('lock_version')->default(1);
        $table->timestamps();
    });
});

afterEach(function (): void {
    Schema::dropIfExists(OptimisticVersionSetArticle::TABLE);
});

it('refuses a root whose lock version is behind the locked row, before the operation runs', function (): void {
    $article = OptimisticVersionSetArticle::query()->create(['title' => 'Before']);
    $stale = $article->fresh();
    $article->update(['title' => 'Concurrent']);
    $ran = false;

    expect(fn () => app(VersionSetManagerInterface::class)->run(
        VersionSetRoot::forModel($stale),
        function () use (&$ran): void {
            $ran = true;
        },
    ))->toThrow(StaleModelLockingException::class);

    expect($ran)->toBeFalse()
        ->and(VersionSet::query()->count())->toBe(0)
        ->and($stale->currentLockVersion())->toBe(1);
});

it('runs a root whose lock version matches the locked row and keeps it', function (): void {
    $article = OptimisticVersionSetArticle::query()->create(['title' => 'Before']);
    $article->update(['title' => 'After']);

    app(VersionSetManagerInterface::class)->run(
        VersionSetRoot::forModel($article),
        function () use ($article): void {
            expect($article->currentLockVersion())->toBe(2);
        },
    );
});

it('refuses a root whose row is gone, naming its class and key', function (): void {
    $article = OptimisticVersionSetArticle::query()->create(['title' => 'Before']);
    OptimisticVersionSetArticle::query()->whereKey($article->getKey())->toBase()->delete();
    $ran = false;

    try {
        app(VersionSetManagerInterface::class)->run(
            VersionSetRoot::forModel($article),
            function () use (&$ran): void {
                $ran = true;
            },
        );
        $this->fail('The run should have been refused.');
    } catch (ModelNotFoundException $exception) {
        expect($exception->getModel())->toBe(OptimisticVersionSetArticle::class)
            ->and($exception->getIds())->toBe([$article->getKey()]);
    }

    expect($ran)->toBeFalse()
        ->and(VersionSet::query()->count())->toBe(0);
});
