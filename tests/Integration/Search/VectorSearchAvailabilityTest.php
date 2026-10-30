<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Services\VectorSearchAvailability;
use Modules\Core\Tests\Stubs\Search\VectorGuardEngineStub;
use Modules\Core\Tests\Stubs\Search\VectorGuardPlainEngineStub;
use Modules\Core\Tests\Stubs\Search\VectorGuardSecondStubModel;
use Modules\Core\Tests\Stubs\Search\VectorGuardStubModel;
use Modules\Core\Tests\Support\FakeVectorProfile;

beforeEach(function (): void {
    Cache::flush();
    FakeVectorProfile::use(new VectorProfile('a:one', 384, 'cosine'));
    VectorGuardStubModel::$engine = new VectorGuardEngineStub(384);
    $this->guard = new VectorSearchAvailability();
});

afterEach(function (): void {
    VectorGuardStubModel::$engine = null;
});

it('is available when there is a profile and the dimensions match', function (): void {
    $result = $this->guard->check(new VectorGuardStubModel());

    expect($result->available)->toBeTrue()->and($result->reason)->toBeNull();
});

it('reports disabled when there is no vector profile', function (): void {
    FakeVectorProfile::none();

    $result = $this->guard->check(new VectorGuardStubModel());

    expect($result->available)->toBeFalse()->and($result->reason)->toBe('disabled');
});

it('reports dimension_mismatch when the index dimensions differ from the profile', function (): void {
    VectorGuardStubModel::$engine = new VectorGuardEngineStub(768);

    $result = $this->guard->check(new VectorGuardStubModel());

    expect($result->available)->toBeFalse()->and($result->reason)->toBe('dimension_mismatch');
});

it('does not treat an index without vectors as a mismatch', function (): void {
    VectorGuardStubModel::$engine = new VectorGuardEngineStub(null);

    expect($this->guard->check(new VectorGuardStubModel())->available)->toBeTrue();
});

it('skips the dimension check for an engine that does not report dimensions', function (): void {
    VectorGuardStubModel::$engine = new VectorGuardPlainEngineStub();

    expect($this->guard->check(new VectorGuardStubModel())->available)->toBeTrue();
});

it('answers disabled before dimension_mismatch', function (): void {
    VectorGuardStubModel::$engine = new VectorGuardEngineStub(768);
    FakeVectorProfile::none();

    expect($this->guard->check(new VectorGuardStubModel())->reason)->toBe('disabled');

    FakeVectorProfile::use(new VectorProfile('a:one', 384, 'cosine'));

    expect($this->guard->check(new VectorGuardStubModel())->reason)->toBe('dimension_mismatch');
});

it('compares the index with the dimensions of the profile in force, not a fixed number', function (): void {
    VectorGuardStubModel::$engine = new VectorGuardEngineStub(768);
    FakeVectorProfile::use(new VectorProfile('b:two', 768, 'cosine'));

    expect($this->guard->check(new VectorGuardStubModel())->available)->toBeTrue();
});

it('asks the engine once within the cache window', function (): void {
    $engine = new VectorGuardEngineStub(384);
    VectorGuardStubModel::$engine = $engine;

    $this->guard->check(new VectorGuardStubModel());
    $this->guard->check(new VectorGuardStubModel());

    expect($engine->calls)->toBe(1);
});

it('asks the engine again after forget()', function (): void {
    $engine = new VectorGuardEngineStub(384);
    VectorGuardStubModel::$engine = $engine;

    $this->guard->check(new VectorGuardStubModel());
    $this->guard->forget();
    $engine->dimensions = 768;
    $result = $this->guard->check(new VectorGuardStubModel());

    expect($engine->calls)->toBe(2)->and($result->reason)->toBe('dimension_mismatch');
});

it('asks the engine again for every model class after forget()', function (): void {
    $engine = new VectorGuardEngineStub(384);
    VectorGuardStubModel::$engine = $engine;

    $this->guard->check(new VectorGuardStubModel());
    $this->guard->check(new VectorGuardSecondStubModel());
    expect($engine->calls)->toBe(2);

    $this->guard->forget();
    $this->guard->check(new VectorGuardStubModel());
    $this->guard->check(new VectorGuardSecondStubModel());

    expect($engine->calls)->toBe(4);
});

it('keeps the key registry alive as long as a dimension key is cached', function (): void {
    $engine = new VectorGuardEngineStub(384);
    VectorGuardStubModel::$engine = $engine;

    $this->guard->check(new VectorGuardStubModel());
    $this->travel(3590)->seconds();
    $this->guard->check(new VectorGuardStubModel());
    $this->travel(40)->seconds();
    expect($engine->calls)->toBe(2);

    $this->guard->forget();
    $this->guard->check(new VectorGuardStubModel());

    expect($engine->calls)->toBe(3);
});
