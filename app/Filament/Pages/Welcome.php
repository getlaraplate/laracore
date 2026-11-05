<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Modules\Core\Services\Docs\ModuleInfoService;
use Override;
use UnitEnum;

final class Welcome extends Page
{
    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'app.navigation.documentation';

    #[Override]
    protected static ?string $slug = 'documentation/welcome';

    #[Override]
    protected static ?int $navigationSort = 1;

    #[Override]
    protected string $view = 'core::filament.pages.welcome';

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('app.pages.welcome');
    }

    public function mount(): void
    {
        //
    }

    /**
     * The application card first, then the modules by name, ignoring letter case.
     */
    public function getGroupedModules(): array
    {
        $moduleInfoService = resolve(ModuleInfoService::class);
        $grouped = $moduleInfoService->groupedModules();

        uksort($grouped, static fn (string $first, string $second): int => [$first !== 'App', mb_strtolower($first)] <=> [$second !== 'App', mb_strtolower($second)]);

        return $grouped;
    }

    #[Override]
    public function getTitle(): string
    {
        return (string) __('app.pages.welcome');
    }
}
