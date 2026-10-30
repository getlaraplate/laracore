<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Utils;

use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use InvalidArgumentException as GlobalInvalidArgumentException;
use LogicException;
use Modules\Core\Contracts\IActivatableModel;
use Modules\Core\Contracts\ILockableModel;
use Modules\Core\Contracts\ISearchableModel;
use Modules\Core\Contracts\ISoftDeletableModel;
use Modules\Core\Contracts\IValidatableModel;
use Modules\Core\Events\TranslatedModelSaved;
use Modules\Core\Filament\FilamentTraitResolver;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Inspector\ModelMetadataRegistry;
use Modules\Core\Models\User;
use Modules\Core\Services\FlagCDNService;
use Modules\Core\Support\PermissionName;
use PHPUnit\Event\InvalidArgumentException;
use ReflectionClass;

trait HasTable
{
    public static function formatValidityColumnState(Model $record): string
    {
        if (! method_exists($record, 'validFromKey') || ! method_exists($record, 'validToKey')) {
            return '';
        }

        $valid_from = $record->{$record->validFromKey()};

        if ($valid_from === null) {
            return '';
        }

        $rows = sprintf(
            '<div class="flex justify-between">
                <span>From:</span>
                <span>%s</span>
            </div>',
            self::formatTableDateTimeValue($valid_from),
        );

        $valid_to = $record->{$record->validToKey()};

        if ($valid_to !== null) {
            $rows .= sprintf(
                '<div class="flex justify-between">
                    <span>Until:</span>
                    <span>%s</span>
                </div>',
                self::formatTableDateTimeValue($valid_to),
            );
        }

        return '<div class="space-y-1">' . $rows . '</div>';
    }

    public static function formatTimestampsColumnState(Model $record, bool $hasSoftDeletes = false): string
    {
        $created_at_column = $record->getCreatedAtColumn() ?? 'created_at';
        $updated_at_column = $record->getUpdatedAtColumn() ?? 'updated_at';

        $rows = sprintf(
            '<div class="flex justify-between">
                <span>Created:</span>
                <span>%s</span>
            </div>
            <div class="flex justify-between">
                <span>Updated:</span>
                <span>%s</span>
            </div>',
            self::formatTableDateTimeValue($record->{$created_at_column}),
            self::formatTableDateTimeValue($record->{$updated_at_column}),
        );

        if ($hasSoftDeletes && $record instanceof ISoftDeletableModel) {
            $deleted_at_column = $record->getDeletedAtColumn() ?? 'deleted_at';
            $deleted_at = $record->{$deleted_at_column};

            if ($deleted_at !== null) {
                $rows .= sprintf(
                    '<div class="flex justify-between">
                        <span>Deleted:</span>
                        <span>%s</span>
                    </div>',
                    self::formatTableDateTimeValue($deleted_at),
                );
            }
        }

        return '<div class="space-y-1">' . $rows . '</div>';
    }

    /**
     * @param  ?callable(Collection<string,Column> $columns):void  $columns
     * @param  ?callable(Collection<string,Action> $actions, Collection<string,BulkAction> $bulk_actions):void  $actions
     * @param  ?callable(Collection<string,BaseFilter> $default_filters):void  $filters
     * @param  list<string>  $fixedActions  Action names kept out of the grouped menu,
     *                                      matched against Action::getName().
     *
     * @throws LogicException
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     * @throws GlobalInvalidArgumentException
     */
    protected static function configureTable(Table $table, ?callable $columns = null, ?callable $actions = null, array $fixedActions = [], ?callable $filters = null): Table
    {
        /** @var User $user */
        $user = Auth::user();

        self::loadUserPermissionsForTable($user);

        // getModel() is nullable, and both calls below would have failed on null:
        // the registry with a type error, ReflectionClass with a fatal.
        $model = $table->getModel();

        if ($model === null) {
            return $table;
        }

        $meta = ModelMetadataRegistry::getInstance()->get($model);
        $model_instance = new ReflectionClass($model)->newInstanceWithoutConstructor();
        $permissions_prefix = sprintf('%s.%s', PermissionName::normalizeConnection($meta->connection), $meta->table);

        $has_soft_deletes = $meta->hasSoftDeletes;
        $has_validity = $meta->hasValidity;
        $has_activation = $meta->hasActivation;
        $has_locks = $meta->hasLocks;
        $has_sorts = $meta->hasSorts;
        $has_searchable = $meta->hasSearchable;
        $has_translations = $meta->hasTranslations;

        if ($has_soft_deletes) {
            $table->recordClasses(static fn ($record): array => $record->deleted_at ? [
                'line-through' => true,
                'text-gray-500' => true,
            ] : []);
        }

        self::configureColumns(
            $table,
            $has_soft_deletes,
            $has_validity,
            $has_locks,
            $has_sorts,
            $has_activation,
            $has_translations,
            $columns,
            $model_instance,
        );

        self::configureActions(
            $table,
            $has_soft_deletes,
            $has_validity,
            $has_searchable,
            $has_activation,
            $has_translations,
            $has_locks,
            $actions,
            $fixedActions,
            $permissions_prefix,
            $user,
        );

        self::configureToolbarActions($table);

        self::configureFilters(
            $table,
            $has_soft_deletes,
            $has_validity,
            $has_locks,
            $has_activation,
            $has_translations,
            $filters,
            $model_instance,
            $permissions_prefix,
            $user,
        );

        if ($has_sorts) {
            $table->reorderable('order_column');
        }

        return $table
            ->striped()
            ->deferLoading()
            ->deselectAllRecordsWhenFiltered()
            ->deferFilters()
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->paginatedWhileReordering()
            ->reorderableColumns()
            ->deferColumnManager(true);
    }

    private static function loadUserPermissionsForTable(?User $user): void
    {
        if (! $user instanceof User) {
            return;
        }

        // Eager load dei permessi e ruoli per evitare N+1 queries
        $user->loadMissing([
            'permissions',
            'roles.permissions',
        ]);
    }

    private static function checkPermissionCached(?User $user, string $permission): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        // Memoized for the current request only. Capturing $user is safe because
        // User implements HasOnceHash, so the memo key is the user identity.
        return once(fn (): bool => $user->can($permission));
    }

    /**
     * Whether the signed-in user holds the `update` permission of the record's table.
     */
    protected static function canUpdateRecord(Model $record): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        $meta = ModelMetadataRegistry::getInstance()->get($record::class);

        return self::checkPermissionCached(
            $user,
            sprintf('%s.%s.update', PermissionName::normalizeConnection($meta->connection), $meta->table),
        );
    }

    /**
     * A boolean column rendered as a switch that saves on click, locked for users who cannot update the record.
     */
    protected static function toggleColumn(string $name): ToggleColumn
    {
        return ToggleColumn::make($name)
            ->alignCenter()
            ->grow(false)
            ->disabled(static fn (Model $record): bool => ! self::canUpdateRecord($record));
    }

    private static function configureColumns(
        Table $table,
        bool $hasSoftDeletes,
        bool $hasValidity,
        bool $hasLocks,
        bool $hasSorts,
        bool $hasActivation,
        bool $hasTranslations,
        ?callable $columns,
        Model $model_instance,
    ): void {
        /** @var Collection<int, Column> $default_columns */
        $default_columns = collect([]);

        // if ($columns === null) {
        //     $inspected_data = Inspect::table($model_instance->getTable(), $model_instance->getConnectionName());
        //     foreach ($inspected_data->columns as $column) {
        //         // TODO: create default columns from table
        //     }
        // }

        if ($hasTranslations) {
            $flag_cdn_service = new FlagCDNService();

            $default_columns->push(
                ImageColumn::make('translations.locale')
                    // The column is built for whichever model the resource declares, so
                    // the relation is reachable only by name: getRelationValue() reads the
                    // loaded one and falls back to loading it, as the magic property does.
                    ->state(fn (Model $record): array => self::translationFlagUrls($record, $flag_cdn_service))
                    ->stacked()
                    ->overlap(1)
                    ->limit(3)
                    ->limitedRemainingText()
                    ->extraImgAttributes(['loading' => 'lazy'])
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->checkFileExistence(false)
                    ->imageHeight('1rem')
                    ->alignCenter()
                    ->grow(false),
            );
        }

        if ($hasValidity) {
            $default_columns->add(
                TextColumn::make('validity')
                    ->getStateUsing(static fn (Model $record): string => self::formatValidityColumnState($record))
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->grow(false)
                    ->html(),
            );
        }

        if ($model_instance instanceof IActivatableModel) {
            $default_columns->add(
                IconColumn::make($model_instance::activationColumn())
                    ->boolean()
                    ->alignCenter()
                    ->grow(false)
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->falseColor('gray'),
            );
        }

        if ($hasLocks) {
            $default_columns->add(
                // `is_locked` is a computed attribute, not a column, so this cannot be sorted or
                // searched in SQL. An ownerless lock is a freeze and gets its own icon, the
                // snowflake the UI spec asks for. Heroicons does not carry one, so Core draws it in
                // the same grammar and serves it from its own set.
                IconColumn::make('is_locked')
                    ->boolean()
                    ->alignCenter()
                    ->icon(static fn (ILockableModel&Model $record): ?string => match (true) {
                        ! $record->isLocked() => null,
                        $record->{$record->getLockedByColumn()} === null => 'laraplate-snowflake',
                        default => 'heroicon-o-lock-closed',
                    })
                    ->tooltip(
                        static function (ILockableModel&Model $record): ?string {
                            if (! $record->isLocked()) {
                                return null;
                            }

                            $locked_at = $record->{$record->getLockedAtColumn()};

                            if ($locked_at instanceof Carbon) {
                                $locked_at = $locked_at->format('Y-m-d H:i:s');
                            }

                            $locked_until = $record->{$record->getLockedUntilColumn()};

                            if ($locked_until instanceof Carbon) {
                                $locked_until = $locked_until->format('Y-m-d H:i:s');
                            }

                            $locked_by = $record->{$record->getLockedByColumn()};

                            $description = $locked_by === null
                                ? sprintf('Frozen at %s', $locked_at)
                                : sprintf('Locked at %s by User #%s', $locked_at, $locked_by);

                            return $locked_until === null
                                ? $description
                                : $description . sprintf(', until %s', $locked_until);
                        },
                    )
                    ->falseIcon(false),
            );
        }

        if ($hasSorts) {
            $default_columns->add(
                TextColumn::make('order_column')
                    ->numeric()
                    ->sortable()
                    ->grow(false)
                    ->alignRight()
                    ->toggleable(isToggledHiddenByDefault: true),
            );
        }

        if ($model_instance->timestamps) {
            $default_columns->add(
                TextColumn::make('timestamps')
                    ->getStateUsing(static fn (Model $record): string => self::formatTimestampsColumnState($record, $hasSoftDeletes))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->grow(false)
                    ->html(),
            );
        }

        if ($columns !== null) {
            $columns($default_columns);
        }

        if ($hasTranslations) {
            self::markTextShownInAnotherLocale($default_columns);
        }

        $strip = FilamentTraitResolver::HAS_TABLE_STRIP_FROM_GENERATED_COLUMNS;
        $default_columns = $default_columns
            ->reject(static fn (Column $column): bool => in_array($column->getName(), $strip, true))
            ->values();

        $default_columns = $default_columns->keyBy(static fn (Column $column): string => $column->getName());
        $primary_key = $model_instance->getKeyName();

        if (! $default_columns->offsetExists($primary_key)) {
            $default_columns->prepend(
                TextColumn::make('id')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            );
        }

        $default_columns->each(static function (Column $column): void {
            if ($column->isSearchable()) {
                $column->searchable(isIndividual: true);
            }
        });

        $table->pushColumns($default_columns->all());

        // Add default column alignment: iterate columns and if boolean align to center
        $default_columns->each(static function (Column $column): void {
            // Check if the column is an IconColumn or has boolean formatting
            if (
                ($column instanceof ImageColumn || $column instanceof IconColumn || $column instanceof ToggleColumn)
                || (method_exists($column, 'isBoolean') && $column->isBoolean())
            ) {
                $column->alignCenter();
            } elseif (method_exists($column, 'isNumeric') && $column->isNumeric()) {
                $column->alignRight();
            }
        });
    }

    /**
     * @param  list<string>  $fixedActions
     */
    private static function configureActions(
        Table $table,
        bool $hasSoftDeletes,
        bool $hasValidity,
        bool $hasSearchable,
        bool $hasActivation,
        bool $hasTranslations,
        bool $hasLocks,
        ?callable $actions,
        array $fixedActions,
        string $permissionsPrefix,
        ?User $user,
    ): void {
        /** @var Collection<int, Action> $default_actions */
        $default_actions = collect([
            ViewAction::make()->hiddenLabel()->modal(true),
        ]);

        /** @var Collection<int, BulkAction> $default_bulk_actions */
        $default_bulk_actions = collect([]);

        if ($hasActivation) {
            $default_actions->push(
                Action::make('activate')
                    ->hiddenLabel()
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->action(static function (IActivatableModel&Model $record): void {
                        $record->activate();
                    }),
                Action::make('deactivate')
                    ->hiddenLabel()
                    ->icon(Heroicon::OutlinedXCircle)
                    ->action(static function (IActivatableModel&Model $record): void {
                        $record->deactivate();
                    }),
            );
        }

        if ($hasValidity) {
            $default_actions->push(
                Action::make('publish')
                    ->hiddenLabel()
                    ->icon(Heroicon::OutlinedPlay)
                    ->action(static function (IValidatableModel&Model $record): void {
                        // setAttribute rather than update([...]): the column names come
                        // from the model at runtime, and update() is typed to the
                        // columns a model declares.
                        $record->setAttribute($record::validFromKey(), now());
                        $record->setAttribute($record::validToKey(), null);
                        $record->save();
                        $record->refresh();
                    })
                    ->disabled(static fn (IValidatableModel&Model $record) => $record->isValid())
                    ->color(static fn (IValidatableModel&Model $record): string => $record->isValid() ? 'gray' : 'success')
                    ->requiresConfirmation(),
                Action::make('unpublish')
                    ->hiddenLabel()
                    ->icon(Heroicon::OutlinedStop)
                    ->color(static fn (IValidatableModel&Model $record): string => $record->isDraft() ? 'gray' : 'warning')
                    ->disabled(static fn (IValidatableModel&Model $record) => $record->isDraft())
                    ->action(static function (IValidatableModel&Model $record): void {
                        $record->setAttribute($record::validToKey(), now());
                        $record->save();
                        $record->refresh();
                    })
                    ->requiresConfirmation(),
            );
        }

        if ($hasTranslations) {
            $default_actions->push(
                Action::make('translate')
                    ->hiddenLabel()
                    ->icon(Heroicon::OutlinedFlag)
                    ->action(static function (Model $record): void {
                        // Emit event instead of dispatching job directly
                        // AI listener will handle the translation job
                        event(new TranslatedModelSaved($record));
                    }),
            );
        }

        if ($hasLocks) {
            // Freeze and unfreeze, never lease. A lease belongs to the edit lifecycle and is taken
            // by opening the form; what a table offers is the deliberate administrative act, and the
            // two rights are deliberately separate permissions: being trusted to block a record and
            // being trusted to unblock other people are not the same responsibility.
            if (self::checkPermissionCached($user, $permissionsPrefix . '.lock')) {
                $default_actions->push(
                    Action::make('freeze')
                        ->hiddenLabel()
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('warning')
                        ->visible(static fn (ILockableModel&Model $record): bool => ! $record->isLocked())
                        ->requiresConfirmation()
                        ->action(static function (ILockableModel&Model $record): void {
                            // No user: an ownerless lock is a freeze, which blocks everybody.
                            $record->lock();
                        }),
                );
                $default_bulk_actions->add(
                    BulkAction::make('freeze')
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(static function (Collection $records): void {
                            foreach ($records as $record) {
                                if ($record instanceof ILockableModel && ! $record->isLocked()) {
                                    $record->lock();
                                }
                            }
                        }),
                );
            }

            if (self::checkPermissionCached($user, $permissionsPrefix . '.unlock')) {
                $default_actions->push(
                    Action::make('unfreeze')
                        ->hiddenLabel()
                        ->icon(Heroicon::OutlinedLockOpen)
                        ->visible(static fn (ILockableModel&Model $record): bool => $record->isLocked())
                        ->requiresConfirmation()
                        ->action(static function (ILockableModel&Model $record): void {
                            // The permission has already established the right to lift somebody
                            // else's lock, so the trait's own owner check would only get in the way.
                            $record->forceUnlock();
                        }),
                );
                $default_bulk_actions->add(
                    BulkAction::make('unfreeze')
                        ->icon(Heroicon::OutlinedLockOpen)
                        ->requiresConfirmation()
                        ->action(static function (Collection $records): void {
                            foreach ($records as $record) {
                                if ($record instanceof ILockableModel && $record->isLocked()) {
                                    $record->forceUnlock();
                                }
                            }
                        }),
                );
            }
        }

        if ($hasSearchable) {
            $default_actions->add(
                Action::make('reindex')
                    ->hiddenLabel()
                    ->icon(Heroicon::ArrowPath)
                    ->action(static function (ISearchableModel&Model $record): void {
                        $record->reindex();
                    }),
            );
            $default_bulk_actions->add(
                BulkAction::make('reindex')
                    ->icon(Heroicon::ArrowPath)
                    ->action(static function (Collection $records): void {
                        foreach ($records as $record) {
                            if ($record instanceof ISearchableModel) {
                                $record->reindex();
                            }
                        }
                    }),
            );
        }

        if (self::checkPermissionCached($user, $permissionsPrefix . '.update')) {
            $default_actions->add(
                EditAction::make()
                    ->hiddenLabel()
                    ->modal(true),
            );
        }

        if ($hasSoftDeletes) {
            if (self::checkPermissionCached($user, $permissionsPrefix . '.restore')) {
                $default_actions->add(
                    RestoreAction::make()
                        ->hiddenLabel()
                        ->requiresConfirmation(),
                );
                $default_bulk_actions->add(
                    RestoreBulkAction::make()
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation(),
                );
            }

            if (self::checkPermissionCached($user, $permissionsPrefix . '.delete')) {
                $default_actions->add(
                    DeleteAction::make()
                        ->icon(Heroicon::OutlinedEyeSlash)
                        ->hiddenLabel(),
                );
                $default_bulk_actions->add(
                    DeleteBulkAction::make()
                        ->icon(Heroicon::OutlinedEyeSlash)
                        ->deselectRecordsAfterCompletion(),
                );
            }
        }

        if (self::checkPermissionCached($user, $permissionsPrefix . '.forceDelete')) {
            $default_actions->add(
                ForceDeleteAction::make()
                    ->visible(true)
                    ->requiresConfirmation()
                    ->hiddenLabel(),
            );
            $default_bulk_actions->add(
                ForceDeleteBulkAction::make()
                    ->visible(true)
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion(),
            );
        }

        if ($actions !== null) {
            $actions($default_actions);
        }

        $fixed_actions_list = [];
        $grouped_actions_list = [];
        // keyBy returns a new collection rather than rekeying this one, so discarding
        // the result left the loop below iterating over integer keys and the
        // in_array() against $fixedActions could never match: every action fell
        // through to the group, whatever the caller asked to keep fixed.
        $default_actions = $default_actions->keyBy(static fn (Action $action): string => (string) $action->getName());

        foreach ($default_actions as $name => $action) {
            if ($fixedActions !== [] && in_array($name, $fixedActions, true)) {
                $fixed_actions_list[] = $action;
            } else {
                $grouped_actions_list[] = $action;
            }
        }

        if ($grouped_actions_list !== []) {
            $fixed_actions_list[] = ActionGroup::make($grouped_actions_list);
        }

        $table->recordActions($fixed_actions_list);

        if ($default_bulk_actions->isNotEmpty()) {
            $table->toolbarActions([
                BulkActionGroup::make($default_bulk_actions->all()),
            ]);
        }
    }

    /**
     * Pushed after configureActions(), whose toolbarActions() call replaces whatever the
     * toolbar held. Both buttons call Livewire directly, as Filament's own remove-all
     * filter indicator does: nothing to mount, and removeTableFilters() also clears the
     * global and per-column searches and rewrites the filters persisted in session.
     */
    private static function configureToolbarActions(Table $table): void
    {
        $table->pushToolbarActions([
            Action::make('reloadTable')
                ->label(__('app.tables.buttons.reload'))
                ->tooltip(__('app.tables.buttons.reload'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->iconButton()
                ->action('$refresh')
                ->livewireTarget('$refresh'),
            Action::make('clearTableFilters')
                ->label(__('app.actions.clearFilters'))
                ->tooltip(__('app.actions.clearFilters'))
                // Heroicons has no eraser: Core draws it in the same grammar, as for the snowflake.
                ->icon('laraplate-eraser')
                ->color('gray')
                ->iconButton()
                ->action('removeTableFilters')
                ->livewireTarget('removeTableFilters,removeTableFilter'),
        ]);
    }

    private static function configureFilters(
        Table $table,
        bool $hasSoftDeletes,
        bool $hasValidity,
        bool $hasLocks,
        bool $hasActivation,
        bool $hasTranslations,
        ?callable $filters,
        Model $model_instance,
        string $permissionsPrefix,
        ?User $user,
    ): void {
        $default_filters = collect([]);

        // FILTERS
        if ($hasSoftDeletes && $model_instance instanceof ISoftDeletableModel && self::checkPermissionCached($user, $permissionsPrefix . '.restore')) {
            $deleted_at_column = $model_instance->getDeletedAtColumn();
            $default_filters->push(
                // TrashedFilter::make(),
                SelectFilter::make($deleted_at_column)
                    ->label(__('app.fields.deleted_at'))
                    ->attribute($deleted_at_column)
                    ->options([
                        // 'all' => 'All',
                        'none' => __('app.filters.withoutTrashed'),
                        'only' => __('app.filters.onlyTrashed'),
                        'today' => __('app.filters.today'),
                        'week' => __('app.filters.week'),
                        'month' => __('app.filters.month'),
                        'year' => __('app.filters.year'),
                    ])
                    // Built only when the model is soft-deletable, so the query is one
                    // over a soft-deletable model. Saying so is what resolves the
                    // trashed scopes: see ISoftDeletableModel.
                    ->query(static function (Builder $query, array $data) use ($deleted_at_column): Builder {
                        /** @var Builder<Model&ISoftDeletableModel> $query */
                        return $query->when($data['value'], static function (Builder $query, mixed $value) use ($deleted_at_column): Builder {
                            /** @var Builder<Model&ISoftDeletableModel> $query */
                            return match ($value) {
                                // 'all' => $query->withTrashed(),
                                'none' => $query->withoutTrashed(),
                                'only' => $query->onlyTrashed(),
                                'today' => $query->onlyTrashed()->whereDate($deleted_at_column, '>=', today()),
                                'week' => $query->onlyTrashed()->whereDate($deleted_at_column, '>=', now()->startOfWeek()),
                                'month' => $query->onlyTrashed()->whereDate($deleted_at_column, '>=', now()->startOfMonth()),
                                'year' => $query->onlyTrashed()->whereDate($deleted_at_column, '>=', now()->startOfYear()),
                                default => $query->withoutGlobalScope('deleted'),
                            };
                        });
                    }),
            );
        }

        if ($model_instance instanceof ILockableModel) {
            $locked_at_column = $model_instance->getLockedAtColumn();
            $default_filters->push(
                // TernaryFilter::make('is_locked')
                //     ->label('Locked')
                //     ->attribute('is_locked')
                //     ->nullable()
                SelectFilter::make($locked_at_column)
                    ->attribute($locked_at_column)
                    ->options([
                        // 'all' => 'All',
                        'none' => __('app.filters.withoutLocked'),
                        'only' => __('app.filters.onlyLocked'),
                        'today' => __('app.filters.today'),
                        'week' => __('app.filters.week'),
                        'month' => __('app.filters.month'),
                        'year' => __('app.filters.year'),
                    ])
                    ->query(static function (Builder $query, array $data) use ($locked_at_column): Builder {
                        /** @var Builder<Model&ILockableModel> $query */
                        return $query->when($data['value'], static function (Builder $query, mixed $value) use ($locked_at_column): Builder {
                            /** @var Builder<Model&ILockableModel> $query */
                            return match ($value) {
                                // `onlyLocked` and `withoutLocked` were never defined anywhere: every branch
                                // of this filter threw. The scopes are `locked` and `unlocked`, and they
                                // account for expiry, so the filter now agrees with the model.
                                'none' => $query->unlocked(),
                                'only' => $query->locked(),
                                'today' => $query->locked()->whereDate($locked_at_column, '<=', today()),
                                'week' => $query->locked()->whereDate($locked_at_column, '<=', now()->startOfWeek()),
                                'month' => $query->locked()->whereDate($locked_at_column, '<=', now()->startOfMonth()),
                                'year' => $query->locked()->whereDate($locked_at_column, '<=', now()->startOfYear()),
                                default => $query,
                            };
                        });
                    }),
            );
        }

        if ($model_instance instanceof IActivatableModel) {
            $default_filters->add(
                TernaryFilter::make($model_instance::activationColumn())
                    ->label(__('app.fields.active'))
                    ->attribute($model_instance::activationColumn())
                    ->nullable(),
            );
        }

        if ($hasValidity) {
            $default_filters->add(
                SelectFilter::make('is_valid')
                    // 'Expiring' asks the scope with no window, so each model answers
                    // with its own: see HasValidity::expiringWithinHours().
                    ->options([
                        // 'all' => 'All',
                        'valid' => __('app.filters.valid'),
                        'scheduled' => __('app.filters.scheduled'),
                        'expiring' => __('app.filters.expiring'),
                        'expired' => __('app.filters.expired'),
                        'draft' => __('app.filters.draft'),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        /** @var Builder<Model&IValidatableModel> $query */
                        return match ($data['value']) {
                            // 'all' => $query,
                            'valid' => $query->valid(),
                            'scheduled' => $query->scheduled(),
                            'expiring' => $query->expiring(),
                            'expired' => $query->expired(),
                            'draft' => $query->draft(),
                            default => $query,
                        };
                    }),
            );
        }

        if ($hasTranslations) {
            $default_filters->add(
                Filter::make('missing_translation')
                    ->label(static fn (): string => (string) __('app.filters.missingTranslation'))
                    ->query(static fn (Builder $query): Builder => $query->whereDoesntHave(
                        'translations',
                        static fn (Builder $translations): Builder => $translations->where('locale', LocaleContext::get()),
                    )),
            );
            $default_filters->add(
                SelectFilter::make('translations.locale')
                    ->multiple()
                    ->options(LocaleContext::getAvailable())
                    ->query(static fn (Builder $query, array $data): Builder => $query->when($data['values'], static fn (Builder $query, $value): Builder => $query->whereIn('translations.locale', $value))),
            );
        }

        if ($model_instance->timestamps) {
            // Both are nullable: a model with $timestamps = false returns null, and
            // every whereDate() below needs a column name. The same fallback the
            // record actions use.
            $created_at_column = $model_instance->getCreatedAtColumn() ?? 'created_at';
            $updated_at_column = $model_instance->getUpdatedAtColumn() ?? 'updated_at';
            $default_filters->push(
                SelectFilter::make($created_at_column)
                    ->label(__('app.fields.created_at'))
                    ->attribute($created_at_column)
                    ->options([
                        // 'all' => 'All',
                        'today' => __('app.filters.today'),
                        'week' => __('app.filters.week'),
                        'month' => __('app.filters.month'),
                        'year' => __('app.filters.year'),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query->when($data['value'], static fn (Builder $query, $value): Builder => match ($value) {
                        // 'all' => $query,
                        'today' => $query->whereDate($created_at_column, '>=', today()),
                        'week' => $query->whereDate($created_at_column, '>=', now()->startOfWeek()),
                        'month' => $query->whereDate($created_at_column, '>=', now()->startOfMonth()),
                        'year' => $query->whereDate($created_at_column, '>=', now()->startOfYear()),
                        default => $query,
                    })),
                SelectFilter::make($updated_at_column)
                    ->label(__('app.fields.updated_at'))
                    ->attribute($updated_at_column)
                    ->options([
                        // 'all' => 'All',
                        'today' => __('app.filters.today'),
                        'week' => __('app.filters.week'),
                        'month' => __('app.filters.month'),
                        'year' => __('app.filters.year'),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query->when($data['value'], static fn (Builder $query, $value): Builder => match ($value) {
                        // 'all' => $query,
                        'today' => $query->whereDate($updated_at_column, '>=', today()),
                        'week' => $query->whereDate($updated_at_column, '>=', now()->startOfWeek()),
                        'month' => $query->whereDate($updated_at_column, '>=', now()->startOfMonth()),
                        'year' => $query->whereDate($updated_at_column, '>=', now()->startOfYear()),
                        default => $query,
                    })),
            );
        }

        if ($filters !== null) {
            $filters($default_filters);
        }

        $table->pushFilters($default_filters->all()/* , layout: FiltersLayout::Modal */);
    }

    private static function formatTableDateTimeValue(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (! is_string($value) && ! is_int($value)) {
            return '';
        }

        if ($value === '') {
            return '';
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    /**
     * Flag image URLs for a record's translations, one per locale it carries.
     *
     * Written out rather than inlined in the column closure because every value on
     * the way is untyped: the relation is reached by name, its entries are models
     * whose `locale` column no type declares, and a non-string there means the row
     * is unusable rather than renderable.
     *
     * @return list<string>
     */
    /**
     * A translated text column shows the text of another locale where the content locale has no translation
     * ({@see \Modules\Core\Overrides\LocaleScope}); the column then follows the text with that locale, greyed
     * like a description, so the row does not pass for translated.
     *
     * @param  Collection<int, Column>  $columns
     */
    private static function markTextShownInAnotherLocale(Collection $columns): void
    {
        foreach ($columns as $column) {
            if (! $column instanceof TextColumn) {
                continue;
            }

            $name = $column->getName();

            // Asked of the record when the cell renders, not while the table is built: resolving the translated
            // fields loads the translation model, which the table does not otherwise need.
            $column->suffix(static function (Model $record) use ($name): ?HtmlString {
                if (! method_exists($record, 'isTranslatableField') || ! $record->isTranslatableField($name)) {
                    return null;
                }

                $translation = $record->relationLoaded('translation') ? $record->getRelationValue('translation') : null;
                $locale = $translation instanceof Model ? $translation->getAttribute('locale') : null;

                if (! is_string($locale) || $locale === LocaleContext::get()) {
                    return null;
                }

                $code = mb_strtoupper($locale);
                $label = (string) __('app.tables.shownInLocale', ['locale' => $code]);

                return new HtmlString(' <span class="fi-ta-text-description" title="' . e($label) . '">(' . e($code) . ')</span>');
            });
        }
    }

    private static function translationFlagUrls(Model $record, FlagCDNService $flag_cdn_service): array
    {
        $translations = $record->getRelationValue('translations');

        if (! is_iterable($translations)) {
            return [];
        }

        $urls = [];

        foreach ($translations as $translation) {
            $locale = $translation instanceof Model ? $translation->getAttribute('locale') : null;

            if (is_string($locale) && $locale !== '') {
                $urls[] = url($flag_cdn_service->getUrl($locale, 40, 30, 'webp'));
            }
        }

        return $urls;
    }
}
