<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Casts\ModifyRequestData;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Crud\QueryBuilder;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationRoot;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationSubject;

/**
 * A CRUD update that changes a record and its relations is one revision of the record (D42).
 */
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

    $role = Role::factory()->create(['name' => config('permission.roles.superadmin'), 'guard_name' => 'web']);
    $this->admin = User::factory()->create(['username' => 'versioned_crud_' . uniqid(), 'email' => 'versioned_crud_' . uniqid() . '@example.com']);
    $this->admin->assignRole($role);
    auth()->login($this->admin);

    $this->root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $this->subjects = collect(['A', 'B'])->map(static fn (string $name): VersionedRelationSubject => VersionedRelationSubject::query()->create(['name' => $name]));
});

afterEach(function (): void {
    Schema::dropIfExists(VersionedRelationRoot::PIVOT_TABLE);
    Schema::dropIfExists(VersionedRelationSubject::TABLE);
    Schema::dropIfExists(VersionedRelationRoot::TABLE);
});

it('writes the fields and the relations of a CRUD update in one atomic set', function (): void {
    $request = Request::create('/modify', 'PATCH', ['id' => $this->root->getKey()]);
    $request->request->set('id', $this->root->getKey());
    $request->setUserResolver(fn (): User => $this->admin);

    $data = (new ReflectionClass(ModifyRequestData::class))->newInstanceWithoutConstructor();

    foreach ([
        'request' => $request,
        'mainEntity' => $this->root->getTable(),
        'primaryKey' => 'id',
        'model' => new VersionedRelationRoot,
        'connection' => $this->root->getConnectionName(),
        'changes' => ['title' => 'Second'],
        'relations' => ['categories' => $this->subjects->map(static fn (VersionedRelationSubject $subject): int => $subject->getKey())->all()],
    ] as $property => $value) {
        $reflection = new ReflectionProperty(ModifyRequestData::class, $property);
        $reflection->setValue($data, $value);
    }

    $before = VersionSet::query()->max('id');

    (new CrudService(app(AuthorizationService::class), app(QueryBuilder::class)))->update($data);

    $set = VersionSet::query()->where('id', '>', $before)->sole();

    expect($set->capture)->toBe(VersionCaptureQuality::Atomic)
        ->and($set->versions()->get()->map(static fn (Version $row): string => $row->relation_path ?? 'root')->sort()->values()->all())
        ->toBe(['categories', 'categories', 'root']);
});
