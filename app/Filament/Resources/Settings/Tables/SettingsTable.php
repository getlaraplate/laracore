<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\Settings\Tables;

use function Filament\Support\generate_icon_html;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;
use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Filament\Tables\View\Components\Columns\IconColumnComponent\IconComponent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Exceptions\InvalidSettingActionException;
use Modules\Core\Filament\Resources\Settings\SettingResource;
use Modules\Core\Filament\Utils\HasTable;
use Modules\Core\Models\Setting;
use Modules\Core\Services\SettingActionResult;
use Modules\Core\Services\SettingActionRunner;
use Modules\Core\Support\PermissionName;
use Throwable;

final class SettingsTable
{
    use HasTable;

    public static function configure(Table $table): Table
    {
        return self::configureTable(
            table: $table,
            columns: static function (Collection $default_columns): void {
                $default_columns->unshift(...[
                    TextColumn::make('group_name')
                        ->searchable()
                        ->sortable()
                        ->hidden(),
                    TextColumn::make('module')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('name')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('description')
                        ->searchable()
                        ->toggleable(),
                    TextColumn::make('type')
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('value')
                        ->alignCenter()
                        ->color(static fn (Setting $record): ?string => $record->isValueOutsideChoices() ? 'warning' : null)
                        ->icon(static fn (Setting $record): ?Heroicon => $record->isValueOutsideChoices() ? Heroicon::OutlinedExclamationTriangle : null)
                        ->tooltip(static fn (Setting $record): ?string => $record->isValueOutsideChoices()
                            ? 'The saved value is not among the available choices'
                            : null)
                        ->html()
                        ->badge(static fn (Setting $record): bool => self::isFlatList($record->value))
                        ->state(static fn (Setting $record): mixed => match ($record->type) {
                            SettingTypeEnum::Boolean => generate_icon_html(
                                $record->value ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedMinusCircle,
                                attributes: (new FilamentComponentAttributeBag)
                                    ->color(IconComponent::class, $record->value ? 'success' : 'gray'),
                                size: IconSize::Large,
                            ),
                            default => $record->value,
                        }),
                    IconColumn::make('is_public')
                        ->boolean()
                        ->trueIcon(Heroicon::OutlinedCheckCircle)
                        ->trueColor('success')
                        ->falseIcon(false)
                        ->alignCenter()
                        ->toggleable(),
                    IconColumn::make('is_internal')
                        ->boolean()
                        ->trueIcon(Heroicon::OutlinedCheckCircle)
                        ->trueColor('success')
                        ->falseIcon(false)
                        ->alignCenter()
                        ->toggleable(isToggledHiddenByDefault: true),
                    IconColumn::make('encrypted')
                        ->boolean()
                        ->alignCenter()
                        ->trueIcon('heroicon-o-key')
                        ->falseIcon(false)
                        ->toggleable(isToggledHiddenByDefault: false),
                ]);
            },
            filters: static function (Collection $default_filters): void {
                $default_filters->unshift(...[
                    SelectFilter::make('type')
                        ->options([
                            'string' => 'String',
                            'integer' => 'Integer',
                            'float' => 'Float',
                            'boolean' => 'Boolean',
                            'array' => 'Array',
                            'json' => 'JSON',
                            'date' => 'Date',
                            'datetime' => 'DateTime',
                        ]),
                    SelectFilter::make('group_name')
                        ->options(static fn (): array => self::cachedGroupNameOptions()),
                    SelectFilter::make('module')
                        ->options(static fn (): array => self::cachedModuleOptions()),
                    SelectFilter::make('is_public')
                        ->searchable()
                        ->options([
                            '1' => __('app.filters.public'),
                            '0' => __('app.filters.private'),
                        ]),
                    SelectFilter::make('is_internal')
                        ->searchable()
                        ->options([
                            '1' => __('app.filters.internal'),
                            '0' => __('app.filters.notInternal'),
                        ]),
                    SelectFilter::make('encrypted')
                        ->options([
                            '1' => __('app.filters.encrypted'),
                            '0' => __('app.filters.notEncrypted'),
                        ]),
                ]);
            },
            actions: static function (Collection $default_actions): void {
                $default_actions->push(self::runActionAction());
            },
            fixedActions: ['runSettingAction'],
        )
            // ->defaultGroup(
            //     Group::make('group_name')->label('Group Name'),
            // )
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderBy('group_name')
                ->orderBy('name'))
            ->defaultGroup('group_name');
    }

    /**
     * Runs the setting's seeded command. Visible only when the setting carries one and the user
     * may update settings; the command itself comes from the seeder, never from the user.
     */
    private static function runActionAction(): Action
    {
        return Action::make('runSettingAction')
            ->hiddenLabel()
            ->icon(Heroicon::OutlinedPlay)
            ->tooltip(static fn (Setting $record): string => (string) $record->action_command)
            ->visible(static fn (Setting $record): bool => $record->action_command !== null
                && (Auth::user()?->can(PermissionName::forModel($record, 'update')) ?? false))
            ->action(static function (Setting $record): void {
                self::runAction($record);
            });
    }

    private static function runAction(Setting $record): void
    {
        try {
            $result = app(SettingActionRunner::class)->run($record);
        } catch (InvalidSettingActionException $exception) {
            Notification::make()->danger()->title(__('app.messages.actionRefused'))->body($exception->getMessage())->send();

            return;
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title(__('app.states.actionFailed'))->body($exception->getMessage())->send();

            return;
        }

        self::notifyResult($result);
    }

    private static function notifyResult(SettingActionResult $result): void
    {
        if ($result->queued) {
            Notification::make()->info()->title(__('app.messages.commandQueued'))->body($result->commandLine)->send();

            return;
        }

        $notification = Notification::make()->body(self::outputTail($result->output));

        if ($result->succeeded()) {
            $notification->success()->title(__('app.messages.commandCompleted'));
        } else {
            $notification->danger()->title(__('app.messages.commandFailed'));
        }

        $notification->send();
    }

    private static function outputTail(string $output): string
    {
        $output = mb_trim($output);

        return mb_strlen($output) > 1000 ? mb_substr($output, -1000) : $output;
    }

    /**
     * Whether the value is a non-empty list of scalars, rendered as one badge per item.
     */
    private static function isFlatList(mixed $value): bool
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return false;
        }

        return array_all($value, static fn (mixed $item): bool => is_scalar($item));
    }

    /**
     * @return array<string, string>
     */
    private static function cachedGroupNameOptions(): array
    {
        $ttl = max(1, (int) config('core.filament.tabs_counts_ttl_seconds', 60));

        return Cache::remember(
            'filament_settings_distinct_group_name',
            $ttl,
            static fn (): array => SettingResource::getEloquentQuery()
                ->select('group_name')
                ->distinct()
                ->orderBy('group_name')
                ->pluck('group_name', 'group_name')
                ->toArray(),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function cachedModuleOptions(): array
    {
        $ttl = max(1, (int) config('core.filament.tabs_counts_ttl_seconds', 60));

        return Cache::remember(
            'filament_settings_distinct_module',
            $ttl,
            static fn (): array => SettingResource::getEloquentQuery()
                ->select('module')
                ->whereNotNull('module')
                ->distinct()
                ->orderBy('module')
                ->pluck('module', 'module')
                ->toArray(),
        );
    }
}
