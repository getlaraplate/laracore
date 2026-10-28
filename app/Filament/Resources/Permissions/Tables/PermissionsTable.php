<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\Permissions\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Authorization\PermissionEnforcement;
use Modules\Core\Filament\Utils\HasTable;
use Modules\Core\Models\Permission;

final class PermissionsTable
{
    use HasTable;

    public static function configure(Table $table): Table
    {
        return self::configureTable(
            table: $table,
            columns: static function (Collection $default_columns): void {
                $default_columns->unshift(...[
                    TextColumn::make('name')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('guard_name')
                        ->searchable(),
                    TextColumn::make('connection_name')
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('table_name')
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('description')
                        ->searchable(),
                    // Only ever rendered when the answer is "this grants nothing right
                    // now": a badge on every row would be noise, and the rows that need
                    // it are the exception.
                    TextColumn::make('enforcement')
                        ->label(__('app.permissions.note.inert'))
                        ->state(static fn (Permission $record): ?string => app(PermissionEnforcement::class)
                            ->noteFor((string) $record->name, $record->table_name)?->label)
                        ->tooltip(static fn (Permission $record): ?string => app(PermissionEnforcement::class)
                            ->noteFor((string) $record->name, $record->table_name)?->reason)
                        ->badge()
                        ->color('warning')
                        ->placeholder(''),
                ]);
            },
            filters: static function (Collection $default_filters): void {
                $default_filters->unshift(...[
                    SelectFilter::make('guard_name')
                        ->options(static fn (): array => self::cachedDistinctOptions('guard_name'))
                        ->multiple()
                        ->preload(),
                    SelectFilter::make('connection_name')
                        ->options(static fn (): array => self::cachedDistinctOptions('connection_name'))
                        ->preload(),
                    SelectFilter::make('table_name')
                        ->options(static fn (): array => self::cachedDistinctOptions('table_name'))
                        ->preload(),
                ]);
            },
        )
            ->defaultSort('name');
    }

    /**
     * @return array<string, string>
     */
    private static function cachedDistinctOptions(string $column): array
    {
        $ttl = max(1, (int) config('core.filament.tabs_counts_ttl_seconds', 60));

        return Cache::remember(
            "filament_permissions_distinct_{$column}",
            $ttl,
            static fn (): array => Permission::query()
                ->distinct($column)
                ->orderBy($column)
                ->pluck($column, $column)
                ->all(),
        );
    }
}
