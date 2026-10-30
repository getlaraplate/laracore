<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Modules\Core\Filament\Widgets\SearchEngineHealthTableWidget;
use Override;
use UnitEnum;

final class CacheHealth extends Page
{
    // protected string $view = 'core::filament.pages.cache';

    #[Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    #[Override]
    protected static string|UnitEnum|null $navigationGroup = 'app.navigation.health';

    #[Override]
    protected static ?string $slug = 'health/cache';

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('app.pages.caches');
    }

    #[Override]
    public function getTitle(): string
    {
        return (string) __('app.pages.caches');
    }

    // protected static ?int $navigationSort = 1;

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    #[Override]
    protected function getHeaderWidgets(): array
    {
        return [
            SearchEngineHealthTableWidget::class,
        ];
    }
}
