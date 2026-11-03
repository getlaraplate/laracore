<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Filament\Resources\Modules\Pages\EditModule;
use Modules\Core\Models\Module;
use Modules\Core\Tests\Support\HttpContext;

beforeEach(function (): void {
    HttpContext::panelActorWithoutApproval(new Module, ['select', 'update']);
});

it('fills a toggle on a hidden attribute with the value of the record being edited', function (bool $is_active): void {
    $module = Module::query()->create(['name' => 'Demo', 'is_active' => $is_active]);

    Livewire::test(EditModule::class, ['record' => $module->getRouteKey()])
        ->assertFormSet(['is_active' => $is_active]);
})->with([
    'switched on' => true,
    'switched off' => false,
]);

it('keeps a toggle on a hidden attribute on when the edit form is saved untouched', function (): void {
    $module = Module::query()->create(['name' => 'Demo', 'is_active' => true]);

    Livewire::test(EditModule::class, ['record' => $module->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($module->fresh()->is_active)->toBeTrue();
});
