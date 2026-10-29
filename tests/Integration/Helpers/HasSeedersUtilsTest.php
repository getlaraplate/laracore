<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Database\Seeders\Concerns\HasSeedersUtils;
use Modules\Core\Models\User;
use Modules\Core\Seeding\ReconciliationOutcome;
use Modules\Core\Tests\Stubs\Seeders\SeedersApprovalBulkStubModel;
use Modules\Core\Tests\Stubs\Seeders\SeedersRelationParentStubModel;
use Modules\Core\Tests\Stubs\Seeders\SeedersRelationTagStubModel;
use Modules\Core\Tests\Stubs\Seeders\SeedersUtilsTestSeeder;
use Modules\Core\Tests\Stubs\SeedersBulkStubModel;

beforeEach(function (): void {
    Schema::create('seeders_bulk_stub', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('seeders_approval_bulk_stub', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('seeders_relation_parents', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('seeders_relation_children', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->string('note')->nullable();
        $table->timestamps();
    });

    Schema::create('seeders_relation_tags', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('seeders_relation_child_tag', function (Blueprint $table): void {
        $table->unsignedBigInteger('seeders_relation_child_id');
        $table->unsignedBigInteger('seeders_relation_tag_id');
        $table->string('meta')->nullable();
    });
});

it('create persists a user from attributes', function (): void {
    $seeder = new SeedersUtilsTestSeeder;

    $user = $seeder->runCreate();

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->exists)->toBeTrue()
        ->and(User::query()->whereKey($user->getKey())->exists())->toBeTrue();
});

it('create disables approval capture for approval models', function (): void {
    $seeder = new SeedersUtilsTestSeeder;

    $model = $seeder->runCreateWithApprovals();

    expect($model)->toBeInstanceOf(SeedersApprovalBulkStubModel::class)
        ->and($model->exists)->toBeTrue()
        ->and(SeedersApprovalBulkStubModel::query()->whereKey($model->getKey())->exists())->toBeTrue();
});

it('createMany bulk inserts and assigns incrementing ids', function (): void {
    $seeder = new SeedersUtilsTestSeeder;

    $models = $seeder->runCreateMany(3);

    expect($models)->toHaveCount(3)
        ->and(SeedersBulkStubModel::query()->count())->toBe(3);

    foreach ($models as $model) {
        expect($model->getKey())->not->toBeNull();
    }
});

it('assigns bulk insert ids from the seeded model connection', function (): void {
    config()->set('database.connections.seeders_affinity', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('seeders_affinity');

    Schema::connection('seeders_affinity')->create('seeders_affinity_bulk', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $model_class = get_class(new class extends Illuminate\Database\Eloquent\Model
    {
        protected $connection = 'seeders_affinity';

        protected $table = 'seeders_affinity_bulk';

        protected $fillable = ['name'];
    });

    $seeder = new class extends Illuminate\Database\Seeder
    {
        use HasSeedersUtils;

        /**
         * @param  class-string<Illuminate\Database\Eloquent\Model>  $class
         * @param  array<int,array<string,mixed>>  $items
         * @return Collection<int,Illuminate\Database\Eloquent\Model>
         */
        public function createManyOn(string $class, array $items): Collection
        {
            return $this->createMany($class, $items);
        }
    };

    try {
        $models = $seeder->createManyOn($model_class, [
            ['name' => 'first'],
            ['name' => 'second'],
        ]);

        $persisted_ids = DB::connection('seeders_affinity')
            ->table('seeders_affinity_bulk')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        expect($models->pluck('id')->all())->toBe($persisted_ids)
            ->and($models->every->exists)->toBeTrue()
            ->and(DB::connection('seeders_affinity')->table('seeders_affinity_bulk')->count())->toBe(2);
    } finally {
        DB::disconnect('seeders_affinity');
        DB::purge('seeders_affinity');
    }
});

it('rolls back all incrementing inserts when a later row fails', function (): void {
    config()->set('database.connections.seeders_transaction', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    DB::purge('seeders_transaction');

    Schema::connection('seeders_transaction')->create('seeders_transaction_bulk', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    $model_class = get_class(new class extends Illuminate\Database\Eloquent\Model
    {
        public $timestamps = false;

        protected $connection = 'seeders_transaction';

        protected $table = 'seeders_transaction_bulk';

        protected $fillable = ['name'];
    });

    $seeder = new class extends Illuminate\Database\Seeder
    {
        use HasSeedersUtils;

        public function createManyOn(string $class, array $items): Collection
        {
            return $this->createMany($class, $items);
        }
    };

    try {
        expect(fn () => $seeder->createManyOn($model_class, [
            ['name' => 'first'],
            [],
        ]))->toThrow(Illuminate\Database\QueryException::class);

        expect(DB::connection('seeders_transaction')->table('seeders_transaction_bulk')->count())->toBe(0);
    } finally {
        DB::disconnect('seeders_transaction');
        DB::purge('seeders_transaction');
    }
});

it('rejects mixed resolved connections before incrementing inserts', function (): void {
    foreach (['seeders_mixed_a', 'seeders_mixed_b'] as $connection_name) {
        config()->set("database.connections.{$connection_name}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        DB::purge($connection_name);
        Schema::connection($connection_name)->create('seeders_mixed_bulk', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }

    $model_class = get_class(new class extends Illuminate\Database\Eloquent\Model
    {
        public $timestamps = false;

        protected $connection = 'seeders_mixed_a';

        protected $table = 'seeders_mixed_bulk';

        protected $fillable = ['name'];

        public function getConnectionName()
        {
            return $this->getAttribute('name') === 'second' ? 'seeders_mixed_b' : 'seeders_mixed_a';
        }
    });

    $seeder = new class extends Illuminate\Database\Seeder
    {
        use HasSeedersUtils;

        public function createManyOn(string $class, array $items): Collection
        {
            return $this->createMany($class, $items);
        }
    };

    try {
        expect(fn () => $seeder->createManyOn($model_class, [
            ['name' => 'first'],
            ['name' => 'second'],
        ]))->toThrow(LogicException::class);

        expect(DB::connection('seeders_mixed_a')->table('seeders_mixed_bulk')->count())->toBe(0)
            ->and(DB::connection('seeders_mixed_b')->table('seeders_mixed_bulk')->count())->toBe(0);
    } finally {
        foreach (['seeders_mixed_a', 'seeders_mixed_b'] as $connection_name) {
            DB::disconnect($connection_name);
            DB::purge($connection_name);
        }
    }
});

it('rejects mixed resolved connections before non-incrementing bulk inserts', function (): void {
    foreach (['seeders_non_incrementing_a', 'seeders_non_incrementing_b'] as $connection_name) {
        config()->set("database.connections.{$connection_name}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        DB::purge($connection_name);
        Schema::connection($connection_name)->create('seeders_non_incrementing_bulk', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
        });
    }

    $model_class = get_class(new class extends Illuminate\Database\Eloquent\Model
    {
        public $incrementing = false;

        public $timestamps = false;

        protected $connection = 'seeders_non_incrementing_a';

        protected $table = 'seeders_non_incrementing_bulk';

        protected $fillable = ['id', 'name'];

        protected $keyType = 'string';

        public function getConnectionName()
        {
            return $this->getAttribute('name') === 'second'
                ? 'seeders_non_incrementing_b'
                : 'seeders_non_incrementing_a';
        }
    });

    $seeder = new class extends Illuminate\Database\Seeder
    {
        use HasSeedersUtils;

        public function createManyOn(string $class, array $items): Collection
        {
            return $this->createMany($class, $items);
        }
    };

    try {
        expect(fn () => $seeder->createManyOn($model_class, [
            ['id' => 'first-id', 'name' => 'first'],
            ['id' => 'second-id', 'name' => 'second'],
        ]))->toThrow(LogicException::class);

        expect(DB::connection('seeders_non_incrementing_a')->table('seeders_non_incrementing_bulk')->count())->toBe(0)
            ->and(DB::connection('seeders_non_incrementing_b')->table('seeders_non_incrementing_bulk')->count())->toBe(0);
    } finally {
        foreach (['seeders_non_incrementing_a', 'seeders_non_incrementing_b'] as $connection_name) {
            DB::disconnect($connection_name);
            DB::purge($connection_name);
        }
    }
});

it('createMany returns empty collection for empty items', function (): void {
    $seeder = new SeedersUtilsTestSeeder;

    expect($seeder->runCreateManyEmpty())->toBeEmpty();
});

it('createMany disables approval capture for approval models', function (): void {
    $seeder = new SeedersUtilsTestSeeder;

    $models = $seeder->runCreateManyWithApprovals();

    expect($models)->toHaveCount(1)
        ->and(SeedersApprovalBulkStubModel::query()->count())->toBe(1);
});

it('create executes callable method relation fallback branch', function (): void {
    $seeder = new SeedersUtilsTestSeeder;

    $child = $seeder->runCreateWithCallableMethod('custom-note');

    expect($child->note)->toBe('custom-note');
});

it('create associates belongs-to relations', function (): void {
    $seeder = new SeedersUtilsTestSeeder;
    $parent = SeedersRelationParentStubModel::query()->create(['name' => 'parent']);

    $child = $seeder->runCreateWithBelongsTo($parent);

    expect($child->parent_id)->toBe($parent->id);
});

it('create syncs belongs-to-many relations with and without pivot values', function (): void {
    $seeder = new SeedersUtilsTestSeeder;
    $tags = collect([
        SeedersRelationTagStubModel::query()->create(['name' => 't1']),
        SeedersRelationTagStubModel::query()->create(['name' => 't2']),
    ]);

    $child_a = $seeder->runCreateWithBelongsToMany($tags);
    $child_b = $seeder->runCreateWithBelongsToManyPivot($tags);

    expect($child_a->tags()->count())->toBe(2)
        ->and($child_b->tags()->count())->toBe(2)
        ->and(
            DB::table('seeders_relation_child_tag')
                ->where('seeders_relation_child_id', $child_b->id)
                ->where('meta', 'seeded')
                ->exists(),
        )->toBeTrue();
});

it('logOperation writes creating/updating output', function (): void {
    $seeder = new SeedersUtilsTestSeeder;
    $command = Mockery::mock(Illuminate\Console\Command::class);
    $command->shouldReceive('line')->twice();
    $seeder->setCommandForTests($command);

    $seeder->runLogOperation(SeedersRelationTagStubModel::class);
    SeedersRelationTagStubModel::query()->create(['name' => 'already']);
    $seeder->runLogOperation(SeedersRelationTagStubModel::class);
});

it('logOutcome prints nothing to update when the step changed no row', function (): void {
    $seeder = new SeedersUtilsTestSeeder;
    $command = Mockery::mock(Illuminate\Console\Command::class);
    $command->shouldReceive('line')->once()->with('    - nothing to update');
    $seeder->setCommandForTests($command);

    $seeder->runLogOutcome(0, 0, 12);
});

it('logOutcome prints the counts when the step created or realigned rows', function (): void {
    $seeder = new SeedersUtilsTestSeeder;
    $command = Mockery::mock(Illuminate\Console\Command::class);
    $command->shouldReceive('line')->once()->with('    - created 2, realigned 0, unchanged 5');
    $command->shouldReceive('line')->once()->with('    - created 0, realigned 1, unchanged 3');
    $seeder->setCommandForTests($command);

    $seeder->runLogOutcome(2, 0, 5);
    $seeder->runLogOutcome(0, 1, 3);
});

it('logReconciliation counts restored rows as realigned', function (): void {
    $seeder = new SeedersUtilsTestSeeder;
    $command = Mockery::mock(Illuminate\Console\Command::class);
    $command->shouldReceive('line')->once()->with('    - created 1, realigned 2, unchanged 4');
    $seeder->setCommandForTests($command);

    $seeder->runLogReconciliation(new ReconciliationOutcome(created: ['a'], realigned: ['b'], restored: ['c'], unchanged: 4));
});
