# Setting actions and command-managed choices: developer and operator guide

## Columns

`core_settings` carries two code-owned columns:

| Column | Type | Meaning |
|---|---|---|
| `action_command` | nullable string | Artisan command line run from the settings grid, with `{attribute}` placeholders |
| `action_queued` | boolean, default `false` | `false` runs the command inside the request, `true` queues it |

Both are outside `Setting::$fillable`, so neither the Filament form nor the CRUD API can write them, and
inside `$hidden`, so API responses never expose them (public settings are readable by guests). Filament
fills forms through `attributesToArray()`, which honours `$hidden`: the edit form reads the two fields
from the record in `afterStateHydrated()` and shows them read-only, only when the setting has an action.

## Declaring an action

A seeder row declares it next to the usual keys:

```php
[
    ...self::setting('features.chat.model', 'ollama:llama3.2:3b', SettingTypeEnum::String, 'ai', 'AI model used by chat', ['ollama:llama3.2:3b']),
    'action_command' => 'ai:models:refresh --setting={name}',
    'action_queued' => true,
]
```

`Seeder::internalSettingsDefinition()` realigns `action_command` and `action_queued` on every seed, like
`type` and `description`, so a changed command reaches existing installations. Rows that declare no action
get `null` / `false`: an upsert needs every row to carry the same columns, and a row whose action was
removed is realigned to none.

## Running an action

`Modules\Core\Services\SettingActionRunner`:

1. Reads `action_command`; a setting without one is refused.
2. Replaces every `{attribute}` with that attribute of the setting. Scalars become strings, booleans
   `true`/`false`, `null` an empty string, arrays and objects JSON. Each value is wrapped in double quotes
   with `"` and `\` escaped: Symfony's `StringInput` reads it as one token, also in the
   `--option={name}` form, so a value holding spaces or a leading `--` cannot add arguments or options.
3. Refuses, before anything runs, with `InvalidSettingActionException`:
   - an unknown placeholder;
   - `{value}` on an encrypted setting (other placeholders of an encrypted setting are fine);
   - a command that is not registered in Artisan.
4. Runs `Artisan::call($line)` (returning exit code and output in `SettingActionResult`) or
   `Artisan::queue($line)` when `action_queued` is set.

A synchronous run happens inside the web request, with the logged-in user: whatever the command writes
follows that user's approval rules. A queued run happens in the console, where approvals do not apply.
Commands come only from seeders, so this is deliberate.

The grid action (`runSettingAction` in `SettingsTable`) is visible when `action_command` is set and the
user holds `{connection}.core_settings.update`. It shows the last 1000 characters of the output.

## Command-managed choices

`Seeder::commandManagedChoicesSettingsDefinition()` is the variant for settings whose choices a command
refreshes: it realigns the same columns as `internalSettingsDefinition()` except `choices`. The seeder's
choices are written when the row is created (use the initial value, so the select works at once) and a
re-seed never overwrites what the command wrote. `SettingsCleaner` selects rows by module state, so a
module may split its rows across both definitions.

`choices` is exempt from approval in `Setting::requiresApprovalWhen()`: users cannot edit it, and the
command writing it runs synchronously from the grid, where it would otherwise be captured.

`Setting::isValueOutsideChoices()` is true for a scalar value missing from a non-empty choice list. List
values (checkbox settings) are never flagged. The grid marks such a value, and the form keeps it
selectable as "(no longer available)" instead of blanking the select.

## Managed settings

`core_settings.managed` (boolean, default `false`, in the create migration) marks a setting whose value
is owned by code, not by people. The column is cast to bool and is not mass-assignable; the seed
reconciler persists it from the seed definition (`managed: true` on `setting()`, or a `'managed' => true`
key in a module's row).

- The settings form shows a managed value read-only (disabled, not dehydrated). The other columns
  (type, name, choices, `is_public`) stay editable.
- `Setting::writeManaged(string $name, mixed $value): void` writes the value without creating a pending
  approval and without value validation, and the observer refreshes the settings overlay. It throws
  `InvalidArgumentException` for a missing or unmanaged setting.
- `core_settings.value` is NOT NULL and `SettingObserver` turns `''` into `null`, so a managed setting is
  cleared by writing the JSON string `null` directly and flushing the setting through
  `SettingsCacheCoordinator`, as the seeder stores an unset value (see the AI `EmbeddingSwitchStore`).

Core seeds no managed setting and has no `search.vector.*` setting: the vector profile is a contract
(`IVectorProfileProvider`) that a module implements. The AI module seeds two managed settings, both written
only by its embedding model switch: `features.embeddings.serving_model` (the `provider:model` key whose vectors
the indexes hold now, for example `openai_compatible:intfloat/multilingual-e5-small`) and
`features.embeddings.switch` (the switch progress as JSON). `features.embeddings.model`, the operator's chosen
target, is a normal dropdown setting and not managed.

## Change confirmations and locks

A module can ask for a confirmation before a setting's new value is saved, or lock the field, by
implementing `Modules\Core\Contracts\ISettingChangeConfirmation` and registering it at boot:
`app(SettingChangeConfirmations::class)->register($confirmation)` (a singleton; `for($name)` returns the
first implementation whose `supports($name)` is true).

| Method | Called | Contract |
|--------|--------|----------|
| `supports(string $settingName): bool` | to find the implementation | |
| `warn(Setting $setting, mixed $newValue): ?SettingChangeWarning` | before the save, and again after it | null means no confirmation. Called twice per confirmed change, so it must be cheap and pure: no writes, no slow calls |
| `confirmed(Setting $setting, mixed $newValue): void` | once, after the confirmed value was saved | not called when the save went to approval, when the value did not change, or when `warn()` on the saved value now returns null |
| `lockedReason(Setting $setting): ?string` | when the form is built | non-null disables the value field and shows the reason as helper text |

`SettingChangeWarning` (`Modules\Core\Data`) is readonly: `string $title`, `list<string> $lines`.
`EditSetting::beforeSave()` holds the save back when the value changed and `warn()` returns a warning,
and opens a modal with the title and lines; confirming runs the normal save, cancelling saves nothing.

The lock is a UI-level lock of the settings form, not a domain invariant: the CRUD API, `Setting::save()`
and approved pending modifications are not blocked by it. A module that needs a real guard enforces it in
its own service (the AI model switch refuses to start while a switch runs). The AI module registers one
confirmation, for `features.embeddings.model` (`Modules/AI/docs/rag/MODULE.md`, *Embedding model and
model switch*).

## Settings in queue workers

Web requests apply the database settings to config through the `ApplyDatabaseSettingsOverlay` middleware.
Console commands apply them once at boot. Queue workers used to stop there, so a setting changed in
Filament reached queued jobs only after a worker restart. `ApplySettingsOverlayBeforeJob` listens to
`JobProcessing` and re-applies the overlay before every job: the worker drops scoped instances before each
job, so it reads a fresh `PerModelSettingResolver` backed by the persistent cache the saving process
invalidated.

Jobs on the `sync` connection are skipped: they run inside the request that dispatched them, whose
middleware already applied the overlay, and re-applying would overwrite config set during that request.

If `bootstrap/cache/events.php` exists (`php artisan event:cache`), it replaces every
`EventServiceProvider::$listen`: after adding a listener, run `php artisan event:clear` or re-cache.
