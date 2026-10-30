<?php

declare(strict_types=1);

use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\Core\Filament\Resources\Media\MediaResource;
use Modules\Core\Filament\Resources\Media\Pages\ListMedia;
use Modules\Core\Filament\Resources\Media\Pages\ViewMedia;
use Modules\Core\Models\Media;
use Modules\Core\Models\MediaDraft;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Tests\Support\FakeVectorProfile;

function mediaPanelUser(): User
{
    if (! class_exists(App\Models\User::class)) {
        class_alias(User::class, App\Models\User::class);
    }

    /** @var App\Models\User $user */
    $user = App\Models\User::query()->create(User::factory()->raw());
    $user->assignRole(Role::findOrCreate(config('permission.roles.superadmin'), 'web'));

    return $user;
}

function makeClaimedMedia(array $custom = [], string $mime = 'image/jpeg', string $name = 'photo', ?string $ownerType = User::class): Media
{
    $media = new Media();
    $media->forceFill([
        'collection_name' => 'default',
        'name' => $name,
        'file_name' => $name . '.bin',
        'mime_type' => $mime,
        'disk' => 'public',
        'size' => 1024,
        'model_type' => $ownerType,
        'model_id' => 1,
        'custom_properties' => $custom,
        'manipulations' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
    $media->saveQuietly();

    return $media;
}

beforeEach(function (): void {
    FakeVectorProfile::none();
    Filament::setCurrentPanel('admin');
});

it('lists claimed media and offers no create', function (): void {
    $this->actingAs(mediaPanelUser());

    $one = makeClaimedMedia(name: 'first');
    $two = makeClaimedMedia(name: 'second');

    Livewire::test(ListMedia::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$one, $two])
        ->assertActionDoesNotExist(CreateAction::class);

    expect(MediaResource::getPages())->toHaveKeys(['index', 'view'])
        ->not->toHaveKey('create')
        ->not->toHaveKey('edit')
        ->and(MediaResource::canCreate())->toBeFalse();
});

it('excludes draft-staged media from the gallery', function (): void {
    $this->actingAs(mediaPanelUser());

    $claimed = makeClaimedMedia(name: 'claimed');
    $draft = makeClaimedMedia(name: 'draft', ownerType: (new MediaDraft())->getMorphClass());

    Livewire::test(ListMedia::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$claimed])
        ->assertCanNotSeeTableRecords([$draft]);
});

it('filters the gallery by mime type', function (): void {
    $this->actingAs(mediaPanelUser());

    $image = makeClaimedMedia(mime: 'image/jpeg', name: 'image');
    $pdf = makeClaimedMedia(mime: 'application/pdf', name: 'document');

    Livewire::test(ListMedia::class)
        ->loadTable()
        ->filterTable('mime_type', 'application/pdf')
        ->assertCanSeeTableRecords([$pdf])
        ->assertCanNotSeeTableRecords([$image]);
});

it('shows the read-only media view with its display metadata', function (): void {
    $this->actingAs(mediaPanelUser());

    $media = makeClaimedMedia(['description' => 'A man smiling', 'keywords' => ['man', 'smile']]);

    Livewire::test(ViewMedia::class, ['record' => $media->getKey()])
        ->assertOk()
        ->assertSee('A man smiling')
        ->assertSee('smile');
});
