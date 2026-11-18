<?php

declare(strict_types=1);

use Doctrine\DBAL\Types\AsciiStringType;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BinaryType;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\DateImmutableType;
use Doctrine\DBAL\Types\DateIntervalType;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\DateTimeTzType;
use Doctrine\DBAL\Types\DateType;
use Doctrine\DBAL\Types\DecimalType;
use Doctrine\DBAL\Types\FloatType;
use Doctrine\DBAL\Types\GuidType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\SimpleArrayType;
use Doctrine\DBAL\Types\SmallIntType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\TimeImmutableType;
use Doctrine\DBAL\Types\TimeType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MatanYadaev\EloquentSpatial\Doctrine\GeometryType;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Inspector\Entities\Column;
use Modules\Core\Inspector\Entities\ForeignKey;
use Modules\Core\Inspector\Entities\Index;
use Modules\Core\Inspector\Inspect;
use Modules\Core\Inspector\Types\DoctrineTypeEnum;
use Modules\Core\Tests\Stubs\Cache\SpyLabelIndex;

it('column entity computes unsigned and length metadata', function (): void {
    $unsigned = new Column('qty', collect([]), null, 'unsignedInteger');
    $with_length = new Column('name', collect([]), null, 'string(120)');

    expect($unsigned->isUnsigned())->toBeFalse()
        ->and($with_length->getLength())->toBe(0);
});

it('foreign key resolves foreign connection and composite flag', function (): void {
    config()->set('database.connections.testing_fk', [
        'driver' => 'sqlite',
        'database' => 'foreign_schema_db',
        'prefix' => '',
    ]);

    $fk = new ForeignKey(
        'fk_posts_user',
        collect(['user_id', 'tenant_id']),
        'foreign_schema_db',
        'users',
        collect(['id', 'tenant_id']),
        'local_schema',
        'sqlite',
    );

    expect($fk->foreignConnection)->toBe('testing_fk')
        ->and($fk->isComposite())->toBeTrue();
});

it('foreign key keeps local connection for same schema and maps object column names', function (): void {
    $fk = new ForeignKey(
        'fk_local',
        collect([(object) ['name' => 'user_id']]),
        'same_schema',
        'users',
        collect([(object) ['name' => 'id']]),
        'same_schema',
        'sqlite',
    );

    expect($fk->foreignConnection)->toBe('sqlite')
        ->and($fk->localColumnNames()->all())->toBe(['user_id'])
        ->and($fk->foreignColumnNames()->all())->toBe(['id'])
        ->and($fk->isComposite())->toBeFalse();
});

it('index entity reports composite and composite primary states', function (): void {
    $composite_primary = new Index('pk_multi', collect(['id', 'tenant_id']), collect(['primary']));

    expect($composite_primary->isComposite())->toBeTrue()
        ->and($composite_primary->isCompositePrimaryKey())->toBeTrue();
});

it('inspect reads table schema and retrieves columns indexes and foreign keys', function (): void {
    $table = 'inspect_demo_' . bin2hex(random_bytes(4));
    $parent = 'inspect_parent_' . bin2hex(random_bytes(4));

    Schema::create($parent, function (Blueprint $blueprint): void {
        $blueprint->id();
    });

    Schema::create($table, function (Blueprint $blueprint) use ($parent): void {
        $blueprint->id();
        $blueprint->string('name');
        $blueprint->foreignId('parent_id')->constrained($parent);
        $blueprint->unique('name');
    });

    try {
        $inspected = Inspect::table($table, config('database.default'));
        $columns = Inspect::columns($table, config('database.default'));
        $indexes = Inspect::indexes($table, config('database.default'));
        $fks = Inspect::foreignKeys($table, config('database.default'));

        expect($inspected)->not->toBeNull()
            ->and($columns->count())->toBeGreaterThan(0)
            ->and($indexes->count())->toBeGreaterThan(0)
            ->and($fks->count())->toBeGreaterThan(0);

        $column = Inspect::column('name', $table, config('database.default'));
        $index = Inspect::index($indexes->first()->name, $table, config('database.default'));
        $foreign = Inspect::foreignKey($fks->first()->name, $table, config('database.default'));

        expect($column)->not->toBeNull()
            ->and($index)->not->toBeNull()
            ->and($foreign)->not->toBeNull();

        // call again to hit cached retrieval path
        $cached = Inspect::table($table, config('database.default'));
        expect($cached)->not->toBeNull();
    } finally {
        Schema::dropIfExists($table);
        Schema::dropIfExists($parent);
    }
});

it('inspect returns null/empty collections for unknown table', function (): void {
    $missing = 'missing_' . bin2hex(random_bytes(4));

    expect(Inspect::table($missing))->toBeNull()
        ->and(Inspect::columns($missing)->all())->toBe([])
        ->and(Inspect::indexes($missing)->all())->toBe([])
        ->and(Inspect::foreignKeys($missing)->all())->toBe([])
        ->and(Inspect::column('id', $missing))->toBeNull()
        ->and(Inspect::index('idx', $missing))->toBeNull()
        ->and(Inspect::foreignKey('fk', $missing))->toBeNull();
});

it('inspect serves a table from the index on the second read and recomputes it after the inspector label is flushed', function (): void {
    $table = 'inspect_cache_' . bin2hex(random_bytes(4));
    Schema::create($table, function (Blueprint $blueprint): void {
        $blueprint->id();
    });

    $index = new SpyLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);

    try {
        $first = Inspect::table($table, config('database.default'));
        $second = Inspect::table($table, config('database.default'));

        expect($first)->not->toBeNull()
            ->and($second)->toEqual($first)
            ->and($index->stores)->toHaveCount(1)
            ->and($index->stores[0]['lookup']->dependencies->labels)->toBe(['inspector'])
            ->and($index->stores[0]['ttl'])->toBe((int) config('cache.duration.long'));

        $index->flush('inspector');
        Inspect::table($table, config('database.default'));

        expect($index->stores)->toHaveCount(2);
    } finally {
        Schema::dropIfExists($table);
    }
});

it('inspect forget drops only the cached table', function (): void {
    $table = 'inspect_forget_' . bin2hex(random_bytes(4));
    Schema::create($table, function (Blueprint $blueprint): void {
        $blueprint->id();
    });

    $index = new SpyLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);

    try {
        Inspect::table($table, config('database.default'));
        Inspect::forget($table, config('database.default'));
        Inspect::table($table, config('database.default'));

        expect($index->stores)->toHaveCount(2);
    } finally {
        Schema::dropIfExists($table);
    }
});

it('parses column collation and index type metadata defensively', function (): void {
    $parse_columns = new ReflectionMethod(Inspect::class, 'parseColumns');
    $parse_columns->setAccessible(true);
    $parse_indexes = new ReflectionMethod(Inspect::class, 'parseIndexes');
    $parse_indexes->setAccessible(true);

    /** @var Illuminate\Support\Collection<int, Column> $columns */
    $columns = $parse_columns->invoke(null, [[
        'name' => 'title',
        'type' => 'varchar',
        'type_name' => 'string',
        'collation' => 'utf8mb4_unicode_ci',
        'nullable' => true,
    ]]);

    /** @var Illuminate\Support\Collection<int, Index> $indexes */
    $indexes = $parse_indexes->invoke(null, [
        [
            'name' => 'idx_title',
            'columns' => 'not-a-list',
            'type' => 'btree',
            'unique' => true,
        ],
        [
            'name' => 'idx_compound',
            'columns' => ['first', 'second'],
        ],
    ]);

    expect($columns->first()->attributes->all())->toContain('nullable', 'utf8mb4_unicode_ci')
        ->and($indexes->first()->attributes->all())->toContain('btree', 'unique')
        ->and($indexes->first()->columns->all())->toBe([])
        ->and($indexes->last()->attributes->all())->toContain('compound');
});

it('parseForeignKeys builds fallback names and keeps composite metadata', function (): void {
    config()->set('database.connections.inspector_fk_external', [
        'driver' => 'sqlite',
        'database' => 'external_schema',
        'prefix' => '',
    ]);

    $method = new ReflectionMethod(Inspect::class, 'parseForeignKeys');
    $method->setAccessible(true);

    /** @var Illuminate\Support\Collection<int, ForeignKey> $fks */
    $fks = $method->invoke(
        null,
        [[
            'columns' => ['user_id', 'tenant_id'],
            'foreign_schema' => 'external_schema',
            'foreign_table' => 'users',
            'foreign_columns' => ['id', 'tenant_id'],
            'on_update' => 'cascade',
            'on_delete' => 'restrict',
        ]],
        'local_schema',
        'sqlite',
    );

    $first = $fks->first();
    expect($first)->toBeInstanceOf(ForeignKey::class)
        ->and($first->name)->toBe('users_user_id_tenant_id')
        ->and($first->isComposite())->toBeTrue()
        ->and($first->foreignConnection)->toBe('inspector_fk_external')
        ->and($first->onUpdate)->toBe('cascade')
        ->and($first->onDelete)->toBe('restrict');
});

it('doctrine type enum maps from string and doctrine type instances', function (): void {
    expect(DoctrineTypeEnum::fromString('integer'))->toBe(DoctrineTypeEnum::Integer)
        ->and(DoctrineTypeEnum::fromString('something_else'))->toBe(DoctrineTypeEnum::Unknown)
        ->and(DoctrineTypeEnum::fromDoctrine(new BigIntType()))->toBe(DoctrineTypeEnum::Bigint)
        ->and(DoctrineTypeEnum::fromDoctrine(new BinaryType()))->toBe(DoctrineTypeEnum::Binary)
        ->and(DoctrineTypeEnum::fromDoctrine(new BlobType()))->toBe(DoctrineTypeEnum::Blob)
        ->and(DoctrineTypeEnum::fromDoctrine(new BooleanType()))->toBe(DoctrineTypeEnum::Boolean)
        ->and(DoctrineTypeEnum::fromDoctrine(new DateType()))->toBe(DoctrineTypeEnum::Date)
        ->and(DoctrineTypeEnum::fromDoctrine(new IntegerType()))->toBe(DoctrineTypeEnum::Integer)
        ->and(DoctrineTypeEnum::fromDoctrine(new DateIntervalType()))->toBe(DoctrineTypeEnum::Dateinterval)
        ->and(DoctrineTypeEnum::fromDoctrine(new DateTimeType()))->toBe(DoctrineTypeEnum::Datetime)
        ->and(DoctrineTypeEnum::fromDoctrine(new DateTimeImmutableType()))->toBe(DoctrineTypeEnum::DatetimeImmutable)
        ->and(DoctrineTypeEnum::fromDoctrine(new DateTimeTzType()))->toBe(DoctrineTypeEnum::Datetimetz)
        ->and(DoctrineTypeEnum::fromDoctrine(new DateTimeTzImmutableType()))->toBe(DoctrineTypeEnum::DatetimetzImmutable)
        ->and(DoctrineTypeEnum::fromDoctrine(new DecimalType()))->toBe(DoctrineTypeEnum::Decimal)
        ->and(DoctrineTypeEnum::fromDoctrine(new AsciiStringType()))->toBe(DoctrineTypeEnum::AsciiString)
        ->and(DoctrineTypeEnum::fromDoctrine(new DateImmutableType()))->toBe(DoctrineTypeEnum::DateImmutable)
        ->and(DoctrineTypeEnum::fromDoctrine(new FloatType()))->toBe(DoctrineTypeEnum::Float)
        ->and(DoctrineTypeEnum::fromDoctrine(new GuidType()))->toBe(DoctrineTypeEnum::Guid)
        ->and(DoctrineTypeEnum::fromDoctrine(new JsonType()))->toBe(DoctrineTypeEnum::Json)
        ->and(DoctrineTypeEnum::fromDoctrine(new SimpleArrayType()))->toBe(DoctrineTypeEnum::SimpleArray)
        ->and(DoctrineTypeEnum::fromDoctrine(new SmallIntType()))->toBe(DoctrineTypeEnum::Smallint)
        ->and(DoctrineTypeEnum::fromDoctrine(new StringType()))->toBe(DoctrineTypeEnum::String)
        ->and(DoctrineTypeEnum::fromDoctrine(new TextType()))->toBe(DoctrineTypeEnum::Text)
        ->and(DoctrineTypeEnum::fromDoctrine(new TimeType()))->toBe(DoctrineTypeEnum::Time)
        ->and(DoctrineTypeEnum::fromDoctrine(new TimeImmutableType()))->toBe(DoctrineTypeEnum::TimeImmutable)
        ->and(DoctrineTypeEnum::fromDoctrine(new GeometryType()))->toBe(DoctrineTypeEnum::Geometry)
        ->and(DoctrineTypeEnum::fromDoctrine(DoctrineTypeEnum::Text))->toBe(DoctrineTypeEnum::Text);
});
