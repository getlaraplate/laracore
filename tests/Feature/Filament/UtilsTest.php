<?php

declare(strict_types=1);

use Filament\Actions\CreateAction;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable as HasTableContract;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Modules\Core\Casts\ActionEnum;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Stubs\Filament\HasFormHarness;
use Modules\Core\Tests\Stubs\Filament\HasRecordsHarness;

beforeEach(function (): void {
    $this->admin = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'Aa1!FilamentAdminPass',
    ]);

    $this->admin_role = Role::factory()->create(['name' => 'admin']);
    $this->admin->roles()->attach($this->admin_role);
    $this->actingAs($this->admin);
});

it('does not inject entity preset fields for models without HasDynamicContents', function (): void {
    $schema = HasFormHarness::run(
        Schema::make()
            ->model(User::class)
            ->components([
                Toggle::make('is_active'),
            ]),
    );

    $names = array_map(
        static fn ($component): ?string => method_exists($component, 'getName') ? $component->getName() : null,
        $schema->getComponents(withHidden: true),
    );

    expect($names)->toContain('is_active')
        ->and($names)->not->toContain('dynamic_entity_id')
        ->and($names)->not->toContain('dynamic_preset_id')
        ->and($names)->not->toContain('presettable_id');
});

it('defaults Toggle fields to stacked label above switch', function (): void {
    $schema = Schema::make()->components([
        Toggle::make('is_active'),
    ]);

    /** @var Toggle $toggle */
    $toggle = $schema->getComponents()[0];

    expect($toggle->isInline())->toBeFalse();
});

it('keeps Toggle non-inline when the form uses inline labels', function (): void {
    $schema = Schema::make()
        ->inlineLabel()
        ->components([
            Toggle::make('is_active'),
        ]);

    /** @var Toggle $toggle */
    $toggle = $schema->getComponents()[0];

    expect($toggle->hasInlineLabel())->toBeTrue()
        ->and($toggle->isInline())->toBeFalse();
});

it('allows Toggle inline override when the form is stacked', function (): void {
    $schema = Schema::make()->components([
        Toggle::make('is_active')->inline(true),
    ]);

    /** @var Toggle $toggle */
    $toggle = $schema->getComponents()[0];

    expect($toggle->isInline())->toBeTrue();
});

it('defaults table filters to a collapsible sidebar after the content', function (): void {
    $table = Table::make($this->createStub(HasTableContract::class));

    expect($table->getFiltersLayout())->toBe(FiltersLayout::AfterContentCollapsible);
});

it('allows a table to override the default filters layout', function (): void {
    $table = Table::make($this->createStub(HasTableContract::class))
        ->filters([], layout: FiltersLayout::Dropdown);

    expect($table->getFiltersLayout())->toBe(FiltersLayout::Dropdown);
});

it('returns create action when user can create records', function (): void {
    $setting = new Setting;
    $permission_name = PermissionName::forModel($setting, ActionEnum::Insert->value);

    $permission = Permission::factory()->create([
        'name' => $permission_name,
        'guard_name' => 'web',
    ]);
    $this->admin_role->givePermissionTo($permission);

    $livewire = $this->createStub(HasTableContract::class);
    $table = Table::make($livewire);
    $harness = new HasRecordsHarness($table);

    $actions = $harness->callHeaderActions();

    expect($actions)->toHaveCount(1)
        ->and($actions[0])->toBeInstanceOf(CreateAction::class);
});

it('returns no header actions when user cannot create records', function (): void {
    $livewire = $this->createStub(HasTableContract::class);
    $table = Table::make($livewire);
    $harness = new HasRecordsHarness($table);

    expect($harness->callHeaderActions())->toBe([]);
});

it('shares table fetch duration and returns parent records', function (): void {
    $livewire = $this->createStub(HasTableContract::class);
    $table = Table::make($livewire);
    $harness = new HasRecordsHarness($table);

    $records = $harness->getTableRecords();

    expect($records)->toBeInstanceOf(Collection::class)
        ->and($records->count())->toBe(3)
        ->and(view()->shared('tableFetchDurationSeconds'))->not->toBeNull();
});

it('applies configured groups while building the table', function (): void {
    $livewire = $this->createStub(HasTableContract::class);
    $table = Table::make($livewire);
    $harness = new HasRecordsHarness($table);

    $groups_property = new ReflectionProperty(HasRecordsHarness::class, 'groups');
    $groups_property->setAccessible(true);
    $groups_property->setValue($harness, [Group::make('group_name')]);

    $result_table = $harness->callMakeTable();

    expect($result_table->getGroups())->toHaveCount(1);
});
