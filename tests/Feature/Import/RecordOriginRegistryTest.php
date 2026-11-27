<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Import\Enums\ExternalRecordState;
use Modules\Core\Import\Support\RecordOriginRegistry;
use Modules\Core\Import\ValueObjects\ExternalRecordIdentity;
use Modules\Core\Models\RecordOrigin;
use Modules\Core\Models\User;

it('provides source-neutral external record identity primitives', function (): void {
    expect(class_exists(ExternalRecordIdentity::class))->toBeTrue()
        ->and(enum_exists(ExternalRecordState::class))->toBeTrue()
        ->and(class_exists(RecordOriginRegistry::class))->toBeTrue();
});

it('keeps a normalized external identity and optional import evidence', function (): void {
    $updated_at = CarbonImmutable::parse('2024-09-21T16:40:53+00:00');
    $fingerprint = hash('sha256', 'normalized payload');

    $identity = new ExternalRecordIdentity(
        sourceKey: 'legacy_symfony:nebula',
        externalId: 'movement:42',
        fingerprint: $fingerprint,
        sourceUpdatedAt: $updated_at,
    );

    expect($identity->sourceKey)->toBe('legacy_symfony:nebula')
        ->and($identity->externalId)->toBe('movement:42')
        ->and($identity->fingerprint)->toBe($fingerprint)
        ->and($identity->sourceUpdatedAt)->toBe($updated_at);
});

it('allows compatibility identities without a fingerprint', function (): void {
    $identity = new ExternalRecordIdentity('naxos_api', '74');

    expect($identity->fingerprint)->toBeNull()
        ->and($identity->sourceUpdatedAt)->toBeNull();
});

it('rejects malformed fingerprints', function (string $fingerprint): void {
    expect(fn () => new ExternalRecordIdentity('source', '42', $fingerprint))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'too short' => 'abc123',
    'uppercase hexadecimal' => mb_strtoupper(hash('sha256', 'payload')),
    'non hexadecimal' => str_repeat('z', 64),
]);

it('classifies missing unchanged and changed source records', function (): void {
    $user = User::factory()->perpetual()->create();
    $registry = app(RecordOriginRegistry::class);
    $identity = new ExternalRecordIdentity(
        'legacy_symfony:nebula',
        'movement:42',
        hash('sha256', 'version one'),
        CarbonImmutable::parse('2024-09-21T16:40:53+00:00'),
    );

    expect($registry->inspect($user, $identity))->toBe(ExternalRecordState::Missing);

    $registry->register($user, $identity, 'Nebula', 'https://legacy.test/movements/42');

    expect($registry->inspect($user, $identity))->toBe(ExternalRecordState::Unchanged)
        ->and($registry->inspect($user, new ExternalRecordIdentity(
            $identity->sourceKey,
            $identity->externalId,
            hash('sha256', 'version two'),
            $identity->sourceUpdatedAt,
        )))->toBe(ExternalRecordState::Changed);
});

it('registers import evidence and resolves the local referable id', function (): void {
    $user = User::factory()->perpetual()->create();
    $registry = app(RecordOriginRegistry::class);
    $source_updated_at = CarbonImmutable::parse('2024-09-21T16:40:53+01:00');
    $fingerprint = hash('sha256', 'normalized payload');
    $identity = new ExternalRecordIdentity(
        'legacy_symfony:nebula',
        'payment:823',
        $fingerprint,
        $source_updated_at,
    );

    $registry->register($user, $identity, 'Nebula', 'https://legacy.test/payments/823');

    $origin = RecordOrigin::query()->sole();

    expect($origin->referable_type)->toBe($user->getMorphClass())
        ->and((int) $origin->referable_id)->toBe((int) $user->getKey())
        ->and($origin->source_key)->toBe('legacy_symfony:nebula')
        ->and($origin->external_id)->toBe('payment:823')
        ->and($origin->fingerprint)->toBe($fingerprint)
        ->and($origin->source_updated_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($origin->source_updated_at?->equalTo($source_updated_at))->toBeTrue()
        ->and($origin->source_label)->toBe('Nebula')
        ->and($origin->url)->toBe('https://legacy.test/payments/823')
        ->and($registry->referableId($user, 'legacy_symfony:nebula', 'payment:823'))->toBe((int) $user->getKey());
});

it('keeps the source instant when the application timezone changes after import', function (): void {
    $user = User::factory()->perpetual()->create();
    $source_updated_at = CarbonImmutable::parse('2026-09-01T10:30:00+02:00');

    config(['app.timezone' => 'Europe/Rome']);
    app(RecordOriginRegistry::class)->register(
        $user,
        new ExternalRecordIdentity('source-a', '42', null, $source_updated_at),
    );

    config(['app.timezone' => 'America/New_York']);
    $origin = RecordOrigin::query()->sole();

    expect($origin->source_updated_at?->equalTo($source_updated_at))->toBeTrue()
        ->and($origin->source_updated_at?->toDateTimeString())->toBe('2026-09-01 04:30:00');
});

it('stores source timestamps set through the model in UTC', function (): void {
    config(['app.timezone' => 'Europe/Rome']);

    $origin = RecordOrigin::factory()->for(User::factory()->perpetual(), 'referable')->create([
        'source_updated_at' => CarbonImmutable::parse('2026-09-01T10:30:00+02:00'),
    ]);

    expect($origin->getRawOriginal('source_updated_at'))->toBe('2026-09-01 08:30:00')
        ->and($origin->fresh()?->source_updated_at?->toDateTimeString())->toBe('2026-09-01 10:30:00');
});

it('keeps external identities isolated by source key and external id', function (): void {
    $first = User::factory()->perpetual()->create();
    $second = User::factory()->perpetual()->create();
    $registry = app(RecordOriginRegistry::class);

    $registry->register($first, new ExternalRecordIdentity('source-a', '42'));
    $registry->register($second, new ExternalRecordIdentity('source-b', '42'));
    $registry->register($second, new ExternalRecordIdentity('source-a', '43'));

    expect($registry->referableId($first, 'source-a', '42'))->toBe((int) $first->getKey())
        ->and($registry->referableId($first, 'source-b', '42'))->toBe((int) $second->getKey())
        ->and($registry->referableId($first, 'source-a', '43'))->toBe((int) $second->getKey())
        ->and(RecordOrigin::query()->count())->toBe(3);
});

it('refreshes an existing external identity instead of duplicating it', function (): void {
    $first = User::factory()->perpetual()->create();
    $second = User::factory()->perpetual()->create();
    $registry = app(RecordOriginRegistry::class);
    $original = new ExternalRecordIdentity('source-a', '42', hash('sha256', 'one'));
    $changed = new ExternalRecordIdentity('source-a', '42', hash('sha256', 'two'));

    $registry->register($first, $original, 'Original');
    $registry->register($second, $changed, 'Changed');

    $origin = RecordOrigin::query()->sole();

    expect((int) $origin->referable_id)->toBe((int) $second->getKey())
        ->and($origin->fingerprint)->toBe($changed->fingerprint)
        ->and($origin->source_label)->toBe('Changed');
});

it('preserves origin creation time when refreshing import evidence', function (): void {
    $user = User::factory()->perpetual()->create();
    $registry = app(RecordOriginRegistry::class);
    $identity = new ExternalRecordIdentity('source-a', '42', hash('sha256', 'one'));

    try {
        CarbonImmutable::setTestNow('2026-08-01 10:00:00');
        $registry->register($user, $identity);

        CarbonImmutable::setTestNow('2026-08-02 11:00:00');
        $registry->register($user, new ExternalRecordIdentity('source-a', '42', hash('sha256', 'two')));

        $origin = RecordOrigin::query()->sole();

        expect($origin->created_at?->toDateTimeString())->toBe('2026-08-01 10:00:00')
            ->and($origin->updated_at?->toDateTimeString())->toBe('2026-08-02 11:00:00');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('uses the referable model connection for registry reads and writes', function (): void {
    config([
        'database.connections.import_affinity' => [
            ...config('database.connections.sqlite'),
            'database' => ':memory:',
        ],
    ]);
    DB::purge('import_affinity');
    $table_name = CoreTables::RecordOrigins->value;

    Schema::connection('import_affinity')->create($table_name, static function (Blueprint $table): void {
        $table->id();
        $table->string('referable_type');
        $table->unsignedBigInteger('referable_id');
        $table->string('source_key');
        $table->string('source_label')->nullable();
        $table->string('external_id')->nullable();
        $table->char('fingerprint', 64)->nullable();
        $table->string('url', 2048)->nullable();
        $table->timestamp('source_updated_at')->nullable();
        $table->timestamps();
    });

    try {
        $referable = new User;
        $referable->setConnection('import_affinity');
        $referable->setAttribute($referable->getKeyName(), 99);
        $referable->exists = true;
        $identity = new ExternalRecordIdentity('source-a', '42', hash('sha256', 'payload'));
        $registry = app(RecordOriginRegistry::class);

        $registry->register($referable, $identity);

        expect(DB::connection('import_affinity')->table($table_name)->count())->toBe(1)
            ->and(RecordOrigin::query()->count())->toBe(0)
            ->and($registry->referableId($referable, 'source-a', '42'))->toBe(99);
    } finally {
        Schema::connection('import_affinity')->dropIfExists($table_name);
        DB::purge('import_affinity');
    }
});

it('drops a malformed url from the source instead of failing the import', function (): void {
    $user = User::factory()->perpetual()->create();
    $registry = resolve(RecordOriginRegistry::class);

    $registry->register($user, new ExternalRecordIdentity('legacy_symfony:nebula', 'payment:824'), 'Nebula', 'not-a-url');

    expect(RecordOrigin::query()->sole()->url)->toBeNull();
});

it('stores a source instant that falls in a local daylight saving gap', function (): void {
    $user = User::factory()->perpetual()->create();
    $source_updated_at = CarbonImmutable::parse('2025-03-30 02:45:37', 'UTC');

    app(RecordOriginRegistry::class)->register(
        $user,
        new ExternalRecordIdentity('naxos_api@liberta', '8423', null, $source_updated_at),
    );

    $origin = RecordOrigin::query()->sole();

    expect($origin->getRawOriginal('source_updated_at'))->toBe('2025-03-30 02:45:37')
        ->and($origin->source_updated_at?->equalTo($source_updated_at))->toBeTrue();
});
