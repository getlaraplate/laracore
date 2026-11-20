<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\Settings\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use JsonException;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Filament\Utils\HasForm;
use Modules\Core\Filament\Utils\JsonChoiceStateCast;
use Modules\Core\Models\Setting;
use Modules\Core\Services\SettingChangeConfirmations;

/**
 * Settings are seeded: only the group, the value and the description can be edited.
 * The value input follows the setting type, which is itself read-only. The action a setting
 * runs is shown read-only; `$hidden` keeps it out of the fill data, so the fields read it
 * from the record.
 */
final class SettingForm
{
    use HasForm;

    public static function configure(Schema $schema): Schema
    {
        self::configureForm($schema);

        return $schema
            ->components([
                Grid::make(4)
                    ->schema([
                        TextInput::make('name')
                            ->disabled()
                            ->dehydrated(false),
                        Select::make('type')
                            ->options(self::typeOptions())
                            ->disabled()
                            ->dehydrated(false),
                        Toggle::make('encrypted')
                            ->inline(false)
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(__('app.help.settingEncrypted')),
                        Toggle::make('is_internal')
                            ->inline(false)
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(__('app.help.settingInternal')),
                    ])
                    ->columnSpanFull(),
                Grid::make(4)
                    ->schema([
                        TextInput::make('group_name')
                            ->required()
                            ->maxLength(50)
                            ->autocomplete(false)
                            ->datalist(static fn (): array => Setting::query()
                                ->select('group_name')
                                ->distinct()
                                ->orderBy('group_name')
                                ->pluck('group_name')
                                ->all())
                            ->dehydrateStateUsing(static fn (?string $state): ?string => $state === null ? null : mb_trim($state))
                            ->helperText(__('app.help.settingGroupName')),
                        Group::make()
                            ->schema(static fn (?Setting $record): array => [self::managedAware(self::valueField($record), $record)])
                            ->columnSpan(2),
                        Toggle::make('is_public')
                            ->inline(false)
                            ->helperText(__('app.help.settingPublic')),
                    ])
                    ->columnSpanFull(),
                TextInput::make('description')
                    ->maxLength(255)
                    ->columnSpanFull(),
                Grid::make(4)
                    ->schema([
                        TextInput::make('action_command')
                            ->disabled()
                            ->dehydrated(false)
                            ->afterStateHydrated(static function (TextInput $component, ?Setting $record): void {
                                $component->state($record?->action_command);
                            })
                            ->columnSpan(3),
                        Toggle::make('action_queued')
                            ->inline(false)
                            ->disabled()
                            ->dehydrated(false)
                            ->afterStateHydrated(static function (Toggle $component, ?Setting $record): void {
                                $component->state((bool) $record?->action_queued);
                            }),
                    ])
                    ->visible(static fn (?Setting $record): bool => $record?->action_command !== null)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Build the value input matching the setting type and its allowed choices.
     */
    public static function valueField(?Setting $record): Field
    {
        $choices = self::choiceOptions($record?->choices);

        return match ($record?->type) {
            SettingTypeEnum::Boolean => Toggle::make('value'),
            SettingTypeEnum::Integer => TextInput::make('value')
                ->required()
                ->integer()
                ->dehydrateStateUsing(static fn (mixed $state): int => (int) $state),
            SettingTypeEnum::Float => TextInput::make('value')
                ->required()
                ->numeric()
                ->step('any')
                ->dehydrateStateUsing(static fn (mixed $state): float => (float) $state),
            SettingTypeEnum::Date => DatePicker::make('value')
                ->required(),
            SettingTypeEnum::Json => self::jsonValueField($record, $choices),
            default => $choices !== []
                ? self::choiceSelect($record, $choices)
                : TextInput::make('value')->required()->maxLength(65535),
        };
    }

    /**
     * Pretty print a JSON value for the code editor.
     */
    public static function encodeJson(mixed $state): string
    {
        return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Decode the code editor content back to the stored value.
     */
    public static function decodeJson(mixed $state): mixed
    {
        if (! is_string($state) || mb_trim($state) === '') {
            return null;
        }

        try {
            return json_decode($state, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $state;
        }
    }

    /**
     * A managed value is written by a command, and a value a module locks is held by it: the field stays
     * visible but cannot be edited.
     */
    private static function managedAware(Field $field, ?Setting $record): Field
    {
        $lockedReason = $record === null ? null : app(SettingChangeConfirmations::class)->for($record->name)?->lockedReason($record);

        if ($lockedReason !== null) {
            return $field
                ->disabled()
                ->dehydrated(false)
                ->helperText($lockedReason);
        }

        if ($record?->managed !== true) {
            return $field;
        }

        return $field
            ->disabled()
            ->dehydrated(false)
            ->helperText(__('app.help.settingWrittenByCommand'));
    }

    /**
     * A value the choices no longer offer stays selectable, labelled as such, so the select is
     * never blank and saving does not drop it.
     *
     * @param  array<string, string>  $choices
     */
    private static function choiceSelect(?Setting $record, array $choices): Select
    {
        $unavailable = $record?->isValueOutsideChoices() ?? false;

        if ($unavailable) {
            $value = (string) $record->value;
            $choices[$value] = $value . ' (no longer available)';
        }

        return Select::make('value')
            ->required()
            ->searchable()
            ->options($choices)
            ->helperText($unavailable ? 'The saved value is no longer among the available choices.' : null);
    }

    /**
     * @param  array<string, string>  $choices
     */
    private static function jsonValueField(Setting $record, array $choices): Field
    {
        $value = $record->value;

        if ($choices !== [] && is_array($value)) {
            return CheckboxList::make('value')
                ->options($choices)
                ->columns(3);
        }

        if ($choices !== []) {
            return Select::make('value')
                ->required()
                ->options(self::jsonChoiceOptions($record->choices))
                ->stateCast(new JsonChoiceStateCast)
                ->dehydrateStateUsing(static fn (mixed $state): mixed => match ($state) {
                    'true' => true,
                    'false' => false,
                    default => $state,
                });
        }

        if (is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $item): bool => is_scalar($item))) {
            return TagsInput::make('value');
        }

        return CodeEditor::make('value')
            ->language(Language::Json)
            ->required()
            ->json()
            ->formatStateUsing(static fn (mixed $state): string => self::encodeJson($state))
            ->dehydrateStateUsing(static fn (mixed $state): mixed => self::decodeJson($state));
    }

    /**
     * @return array<string, string>
     */
    private static function choiceOptions(mixed $choices): array
    {
        if (! is_array($choices)) {
            return [];
        }

        $options = [];

        foreach ($choices as $choice) {
            if (is_scalar($choice)) {
                $options[(string) $choice] = (string) $choice;
            }
        }

        return $options;
    }

    /**
     * The choices of a JSON setting, a boolean keyed `true` or `false` so it is a value of its own: cast to a string,
     * `false` is the empty key, which a required select refuses (`versioning.strategy.*` offers `false` to switch
     * versioning off). `JsonChoiceStateCast` shows a stored boolean as its key, and the select turns the key back into
     * the boolean it stands for.
     *
     * @return array<string, string>
     */
    private static function jsonChoiceOptions(mixed $choices): array
    {
        if (! is_array($choices)) {
            return [];
        }

        $options = [];

        foreach ($choices as $choice) {
            if (is_bool($choice)) {
                $options[self::encodeJson($choice)] = self::encodeJson($choice);
            } elseif (is_scalar($choice)) {
                $options[(string) $choice] = (string) $choice;
            }
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        $options = [];

        foreach (SettingTypeEnum::cases() as $case) {
            $options[$case->value] = $case->name;
        }

        return $options;
    }
}
