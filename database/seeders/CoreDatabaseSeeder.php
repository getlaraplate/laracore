<?php

declare(strict_types=1);

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Casts\ActionEnum;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\ACL;
use Modules\Core\Models\CronJob;
use Modules\Core\Models\Setting;
use Modules\Core\Overrides\Seeder;
use Modules\Core\Seeding\Contracts\DeclaresSeedDependencies;
use Modules\Core\Seeding\ModelCapabilities;
use Modules\Core\Seeding\ModelCapabilityScanner;
use Modules\Core\Seeding\SeedReconciler;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\Services\SettingsCacheCoordinator;
use Modules\Core\Support\PermissionName;
use Override;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as BaseRole;

final class CoreDatabaseSeeder extends Seeder implements DeclaresSeedDependencies
{
    public const VERSIONING_NAME_PREFIX = 'versioning.strategy';

    public const SOFT_DELETES_NAME_PREFIX = 'soft_deletes.enabled';

    public const LOCK_NAME_PREFIX = 'locking.enabled';

    public const OPTIMISTIC_LOCK_NAME_PREFIX = 'locking.optimistic';

    public const TRANSLATION_FALLBACK_NAME_PREFIX = 'translations.locale_fallback';

    public const AUTO_TRANSLATE_NAME_PREFIX = 'translations.auto';

    public const APPROVAL_THRESHOLD_NAME_PREFIX = 'notifications.threshold';

    /**
     * The guard of `/api`. Roles are keyed by (name, guard): the `guest` of `/app` and the `guest` of `/api` are two roles.
     */
    public const string API_GUARD = 'api';

    /**
     * The seeded default roles, keyed by {@see self::roleKey()}.
     *
     * @var Collection<string, BaseRole>
     */
    private Collection $groups;

    /**
     * `defaultRoles()` assigns permissions that only exist once `permission:refresh` has run, so
     * this node must follow {@see PermissionRefreshSeeder}. That seeder is a separate graph node
     * (not a private method here) so other modules — {@see ERPDatabaseSeeder::ensureDomainPermissions()}
     * — can also declare a dependency on it instead of relying on undeclared run order.
     *
     * @return list<class-string>
     */
    #[Override]
    public static function dependsOn(): array
    {
        return [PermissionRefreshSeeder::class];
    }

    /**
     * @return array<string,string>
     */
    public static function getDefaultUserRoles(): array
    {
        return [
            'superadmin' => (string) config('permission.roles.superadmin'),
            'admin' => (string) config('permission.roles.admin'),
            'guest' => (string) config('permission.roles.guest'),
            'system' => (string) config('permission.roles.system'),
        ];
    }

    /**
     * @return array<int, array{name: string, value: mixed, type: SettingTypeEnum, group_name: string, description: string, choices?: array<int, mixed>, managed?: bool}>
     */
    public static function runtimeSettingDefinitions(): array
    {
        return [
            self::setting('auth.email_verification.enabled', false, SettingTypeEnum::Boolean, 'auth', 'Require email verification for new users'),
            self::setting('auth.registration.enabled', false, SettingTypeEnum::Boolean, 'auth', 'Enable public user registration'),
            self::setting('auth.two_factor.enabled', false, SettingTypeEnum::Boolean, 'auth', 'Enable two-factor authentication'),
            self::setting('auth.passkeys.enabled', false, SettingTypeEnum::Boolean, 'auth', 'Enable passkey login (an addition to the password, never a replacement)'),
            self::setting('auth.licenses.enabled', false, SettingTypeEnum::Boolean, 'auth', 'Enable user license checks'),
            self::setting('auth.social_login.enabled', false, SettingTypeEnum::Boolean, 'auth', 'Enable social login providers'),
            self::setting('locking.unlock_allowed', true, SettingTypeEnum::Boolean, 'locking', 'Allow unlocking locked records'),
            self::setting('locking.prevent_modifications', true, SettingTypeEnum::Boolean, 'locking', 'Whether saves, deletes, and replicates on locked models should be blocked'),
            self::setting('locking.prevent_notifications', false, SettingTypeEnum::Boolean, 'locking', 'Prevents notifications to locked records'),
            self::setting('crud.dynamic_entities', false, SettingTypeEnum::Boolean, 'core', 'Enable dynamic entities'),
            self::setting('expose_api', false, SettingTypeEnum::Boolean, 'core', 'Expose the API: gates every /api route, Core and modules'),
            self::setting('soft_deletes.expiration_days', 0, SettingTypeEnum::Integer, 'soft_deletes', 'Days before soft-deleted records are purged (0 = never)'),
            self::setting('translations.cache.enabled', true, SettingTypeEnum::Boolean, 'translations', 'Cache translation results'),
            self::setting('notifications.enabled', true, SettingTypeEnum::Boolean, 'notifications', 'Enable pending approval notifications'),
            self::setting('notifications.channels', ['database'], SettingTypeEnum::Json, 'notifications', 'Approval notification channels', ['mail', 'database']),
            self::setting('notifications.threshold.default', 8, SettingTypeEnum::Integer, 'notifications', 'Default hours before pending approval notification'),
            self::setting('media.search_visibility', 'owner', SettingTypeEnum::String, 'search', 'Media search hits: owner = only when the user can see the media owner, open = owner-agnostic gallery', ['owner', 'open']),
            self::setting('validity.expiring_within_hours', 48, SettingTypeEnum::Integer, 'validity', 'Hours of lead time for the expiring scope when no window is given'),
            self::setting('filament.tabs_counts_ttl_seconds', 60, SettingTypeEnum::Integer, 'filament', 'Seconds the backoffice caches tab counts and filter options'),
            self::setting('media.draft_ttl_hours', 24, SettingTypeEnum::Integer, 'media', 'Hours a pending media draft is kept before it is pruned'),
            self::setting('search.reranker.enabled', false, SettingTypeEnum::Boolean, 'search', 'Enable search reranker'),
            self::setting('search.reranker.top_k', 30, SettingTypeEnum::Integer, 'search', 'Reranker candidate count'),
            self::setting('search.reranker.weight', 0.6, SettingTypeEnum::Float, 'search', 'Reranker share of the final score for the top-K (0 = fused order, 1 = reranker only)'),
            self::setting('search.vector.enabled', false, SettingTypeEnum::Boolean, 'search', 'Enable vector search'),
            self::setting('search.vector.dimensions', 384, SettingTypeEnum::Integer, 'search', 'Vector search dimensions (set by the embedding model switch)', managed: true),
            self::setting('search.vector.similarity', 'cosine', SettingTypeEnum::String, 'search', 'Vector similarity metric (set by the embedding model switch)', ['cosine', 'dot_product', 'euclidean'], managed: true),
            self::setting('search.vector.model', 'openai_compatible:intfloat/multilingual-e5-small', SettingTypeEnum::String, 'search', 'Active embedding model, as provider:model (set by the embedding model switch)', managed: true),
            self::setting('search.vector.suspended_reason', null, SettingTypeEnum::String, 'search', 'Why vector search is suspended, null when it is not (set by the embedding model switch)', managed: true),
            self::setting('search.adaptive_tuning', false, SettingTypeEnum::Boolean, 'search', 'Apply the committed retrieval tuning profile (config/search_tuning.php) per query class; off keeps the fixed planner values'),
            self::setting('search.max_retries', 2, SettingTypeEnum::Integer, 'search', 'Most times a search request may ask to search again after a poor result (the retry parameter is clamped to this)'),
            self::setting('search.deep_per_minute', 6, SettingTypeEnum::Integer, 'search', 'Deep searches one person may start per minute; beyond it the search is served as fast and says rate_limited'),
            self::setting('search.debug_timings', false, SettingTypeEnum::Boolean, 'search', 'Developer diagnostics: add per-stage search timings (intent, plan, vector, ensemble, total, in ms) to the search response meta'),
        ];
    }

    /**
     * The key of a default role: a role is identified by its name and its guard.
     */
    public static function roleKey(string $name, ?string $guard): string
    {
        return "{$name}|{$guard}";
    }

    /**
     * The guard of `/app`, the one the staff roles live on.
     */
    public static function defaultGuard(): string
    {
        $guard = config('auth.defaults.guard', 'web');

        return is_string($guard) ? $guard : 'web';
    }

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Model::unguarded(function (): void {
            $this->defaultSettings();
            $this->defaultRoles();
            $this->defaultSettingAcls();
            $this->defaultUsers();
            $this->ensureAnonymousGuestRoles();
            $this->ensureSystemServiceAccount();
            $this->defaultCrons();
        });

        app(SettingsCacheCoordinator::class)->flushAll();
    }

    private static function setting(string $name, mixed $value, SettingTypeEnum $type, string $group, string $description, ?array $choices = null, bool $managed = false): array
    {
        return [
            'name' => $name,
            'value' => $value,
            'encrypted' => false,
            'choices' => $choices,
            'type' => $type,
            'group_name' => $group,
            'description' => $description,
            'managed' => $managed,
        ];
    }

    private function defaultRoles(): void
    {
        $user_class = user_class();

        /** @var class-string<BaseRole> $role_class */
        $role_class = config('permission.models.role');
        $role_instance = new ReflectionClass($role_class)->newInstanceWithoutConstructor();
        $role_table = $role_instance->getTable();

        /** @var class-string<Permission> $permission_class */
        $permission_class = config('permission.models.permission');
        $user_instance = new ReflectionClass($user_class)->newInstanceWithoutConstructor();
        $user_table = $user_instance->getTable();

        $this->logOperation($role_class);

        $roles = self::getDefaultUserRoles();
        $web = $this->defaultGuard();

        // Every permission exists once per guard. The staff roles live on the default guard and hold only
        // its permissions; the `api` guard has its own roles, granted its own permissions.
        $all_permissions = $permission_class::query()->where('guard_name', $web)->get();
        $public_api_permission_names = [PermissionName::forClass(Setting::class, ActionEnum::Select->value)];

        $roles_data = [
            [
                'name' => $roles['superadmin'],
                'guard_name' => $web,
                'description' => 'superadmin is the only one who can bypass all the system guards and permissions',
                'locked_at' => now(),
            ],
            [
                'name' => $roles['admin'],
                'guard_name' => $web,
                'locked_at' => now(),
                'permissions' => fn () => $all_permissions
                    ->filter(function ($permission) use ($user_table, $role_table) {
                        $isUserOrRoleTable = in_array($permission->table_name, [$user_table, $role_table], true);
                        $isSelectAction = str_ends_with($permission->name, '.' . ActionEnum::Select->value);
                        $isLockAction = str_ends_with($permission->name, '.' . ActionEnum::Lock->value);

                        return ($isUserOrRoleTable || $isSelectAction) && ! $isLockAction;
                    }),
            ],
            // `/app` is for authenticated users only: the guest of the web guard holds no permission.
            [
                'name' => $roles['guest'],
                'guard_name' => $web,
                'locked_at' => now(),
            ],
            // The guest of the api guard is the anonymous reader of `/api`: it holds the public list, each entity
            // narrowed by its ACL. Core grants its own entity, the modules grant theirs.
            [
                'name' => $roles['guest'],
                'guard_name' => self::API_GUARD,
                'description' => 'The anonymous reader of the api: the public list, narrowed by ACLs.',
                'locked_at' => now(),
                'permissions' => fn () => $permission_class::query()
                    ->where('guard_name', self::API_GUARD)
                    ->whereIn('name', $public_api_permission_names)
                    ->get(),
            ],
            // system is not superadmin so develper can decide what to do with it and limit permissions for security reasons
            [
                'name' => $roles['system'],
                'guard_name' => $web,
                'locked_at' => now(),
                'permissions' => fn () => $all_permissions,
                // ->filter(function ($permission) {
                //     return $permission->name === 'system.permissions';
                // }),
            ],
        ];

        $names = array_values(array_unique(array_column($roles_data, 'name')));
        $this->groups = $this->loadGroups($role_class, $names);
        $new_roles = array_filter($roles_data, fn (array $role): bool => ! $this->groups->has(self::roleKey($role['name'], $role['guard_name'])));

        if ($new_roles === []) {
            $this->command?->line('    - nothing to update');

            return;
        }

        $role_instance->getConnection()->transaction(function () use ($role_class, $new_roles): void {
            foreach ($new_roles as &$role) {
                $this->create($role_class, $role);
                $this->command?->line("    - {$role['name']} ({$role['guard_name']}) <fg=green>created</>");
            }
        });

        // Reload keyed roles so defaultUsers() sees IDs created in this transaction.
        $this->groups = $this->loadGroups($role_class, $names);
    }

    /**
     * @param  class-string<BaseRole>  $role_class
     * @param  list<string>  $names
     * @return Collection<string, BaseRole>
     */
    private function loadGroups(string $role_class, array $names): Collection
    {
        return $role_class::query()->withoutGlobalScopes()->whereIn('name', $names)->get(['id', 'name', 'guard_name'])
            ->keyBy(fn (BaseRole $role): string => self::roleKey($role->name, $role->guard_name));
    }

    /**
     * Seed the row-level ACL that limits the `guest` role of the api guard to public settings on
     * `settings.select`. Staff roles have no ACL on that permission and read every setting.
     */
    private function defaultSettingAcls(): void
    {
        $this->logOperation(ACL::class);

        /** @var class-string<Permission> $permission_class */
        $permission_class = config('permission.models.permission');

        $guest = $this->groups->get(self::roleKey(self::getDefaultUserRoles()['guest'], self::API_GUARD));

        if ($guest === null) {
            $this->command?->line('    - api guest role missing, skipping settings ACL');

            return;
        }

        $setting = new Setting;
        $permission_name = PermissionName::forModel($setting, ActionEnum::Select->value);

        $permission = $permission_class::query()->where(['name' => $permission_name, 'guard_name' => self::API_GUARD])->first(['id']);

        if ($permission === null) {
            $this->command?->line("    - permission {$permission_name} missing, skipping settings ACL");

            return;
        }

        if (ACL::query()->where('permission_id', $permission->id)->where('role_id', $guest->id)->exists()) {
            $this->command?->line('    - settings guest ACL already exists');

            return;
        }

        $acl = new ACL;
        $acl->setSkipValidation(true);
        $acl->forceFill([
            'permission_id' => $permission->id,
            'role_id' => $guest->id,
            'filters' => new FiltersGroup(
                filters: [
                    new Filter("{$setting->getTable()}.is_public", true, FilterOperator::Equals),
                ],
                operator: WhereClause::And,
            ),
            'description' => 'Anonymous readers of the api read only public settings.',
            'unrestricted' => false,
            'priority' => 100,
            'is_active' => true,
        ]);
        $acl->save();

        $this->command?->line('    - settings guest ACL <fg=green>created</>');
    }

    private function defaultUsers(): void
    {
        $user_class = user_class();
        $user_instance = new ReflectionClass($user_class)->newInstanceWithoutConstructor();

        $this->logOperation($user_class);

        $roles = self::getDefaultUserRoles();
        $web = $this->defaultGuard();

        $anonymous = config('permission.users.guest');
        $superadmin = config('permission.users.superadmin');
        $admin = config('permission.users.admin');
        $system = config('permission.users.system');

        $users_data = [
            [
                'name' => $superadmin,
                'username' => $superadmin,
                'email' => "{$superadmin}@" . str_replace('_', '', Str::slug(config('app.name'))) . '.com',
                'password' => Str::random(16),
                'email_verified_at' => now(),
                'roles' => [$this->groups->get(self::roleKey($roles['superadmin'], $web))],
                'locked_at' => now(),
                'valid_from' => now(),
                'valid_to' => null,
            ],
            [
                'name' => $admin,
                'username' => $admin,
                'email' => "{$admin}@" . str_replace('_', '', Str::slug(config('app.name'))) . '.com',
                'password' => Str::random(16),
                'email_verified_at' => now(),
                'roles' => [$this->groups->get(self::roleKey($roles['admin'], $web))],
                'locked_at' => now(),
                'valid_from' => now(),
                'valid_to' => null,
            ],
            [
                'name' => $anonymous,
                'username' => $anonymous,
                'email' => "{$anonymous}@" . str_replace('_', '', Str::slug(config('app.name'))) . '.com',
                'password' => Str::random(16),
                'email_verified_at' => now(),
                'roles' => [
                    $this->groups->get(self::roleKey($roles['guest'], $web)),
                    $this->groups->get(self::roleKey($roles['guest'], self::API_GUARD)),
                ],
                'locked_at' => now(),
                'valid_from' => now(),
                'valid_to' => null,
            ],
            [
                'name' => $system,
                'username' => $system,
                'email' => "{$system}@" . str_replace('_', '', Str::slug(config('app.name'))) . '.com',
                'password' => Str::random(64),
                'email_verified_at' => now(),
                'is_service_account' => true,
                'roles' => [$this->groups->get(self::roleKey($roles['system'], $web))],
                'locked_at' => now(),
                'valid_from' => now(),
                'valid_to' => null,
            ],
        ];

        $seed_usernames = array_column($users_data, 'username');
        $existing_users = $user_class::query()->withoutGlobalScopes()->whereIn('username', $seed_usernames)->get(['id', 'username'])->keyBy('username');
        $new_users = array_filter($users_data, fn ($user) => ! isset($existing_users[$user['username']]));

        if ($new_users === []) {
            $this->command?->line('    - nothing to update');

            return;
        }

        $user_instance->getConnection()->transaction(function () use ($user_class, $new_users, $superadmin): void {
            foreach ($new_users as &$user) {
                $this->create($user_class, $user);
                $this->command?->line("    - {$user['username']} <fg=green>created</>");

                if ($user['username'] === $superadmin) {
                    $this->command?->line("      with password: {$user['password']}");
                }
            }
        });
    }

    /**
     * The anonymous user holds the `guest` role of both guards, also when it was seeded before the api role existed.
     */
    private function ensureAnonymousGuestRoles(): void
    {
        $user_class = user_class();
        $anonymous_id = $user_class::query()->withoutGlobalScopes()->where('username', config('permission.users.guest'))->value('id');

        if ($anonymous_id === null) {
            return;
        }

        $guest = self::getDefaultUserRoles()['guest'];

        foreach ([$this->defaultGuard(), self::API_GUARD] as $guard) {
            $this->groups->get(self::roleKey($guest, $guard))?->users()->syncWithoutDetaching([$anonymous_id]);
        }
    }

    /**
     * The system user never signs in: it is a service account, also when it was seeded before the flag existed. The
     * anonymous user is not one, since a service account cannot take the guest identity.
     */
    private function ensureSystemServiceAccount(): void
    {
        $system = config('permission.users.system');

        if (! is_string($system) || $system === '') {
            return;
        }

        $user_class = user_class();
        $user = new $user_class();

        $user_class::query()
            ->withoutGlobalScopes()
            ->where($user->qualifyColumn('username'), $system)
            ->where($user->qualifyColumn('is_service_account'), false)
            ->update(['is_service_account' => true]);
    }

    private function defaultSettings(): void
    {
        $this->logOperation(Setting::class);

        $core_settings = [
            [
                'name' => 'default_language',
                // The installation default, not app.locale: that is the language of the request running the seeder.
                'value' => config('app.default_locale'),
                'encrypted' => false,
                'choices' => translations(onlyActive: false),
                'type' => SettingTypeEnum::String,
                'group_name' => 'core',
                'description' => 'Default language: translations fall back to it and automatic translations start from it. Changing it with content in place moves both to the new language; content translated only in the old one loses its fallback.',
            ],
            [
                'name' => 'crud.pagination',
                'value' => 20,
                'encrypted' => false,
                'choices' => null,
                'type' => SettingTypeEnum::Integer,
                'group_name' => 'core',
                'description' => 'Default pagination for API calls',
            ],
            [
                'name' => 'auth.max_concurrent_sessions',
                'value' => PHP_INT_MAX,
                'encrypted' => false,
                'choices' => null,
                'type' => SettingTypeEnum::Integer,
                'group_name' => 'auth',
                'description' => 'Maximum number of concurrent sessions',
            ],
        ];

        array_push($core_settings, ...self::runtimeSettingDefinitions());

        $all_model_classes = models();
        $capabilities = app(ModelCapabilityScanner::class)->scan();

        $scanned_classes = array_column($capabilities, 'modelClass');

        foreach (array_diff($all_model_classes, $scanned_classes) as $skipped_class) {
            $this->command?->warn("    - skipped {$skipped_class}: capabilities could not be resolved (see log)");
        }

        $default_approval_threshold = (int) config('core.notifications.threshold.default', 8);

        // Group derived settings by the module that actually owns the model
        // they describe, not by the module running this seeder: a MES model's
        // version_strategy_* row must be reconciled with module = 'MES' so
        // SettingsCleaner can ever classify it Disabled/Absent and remove it.
        //
        // "Owns the model" is resolved from wherever `$table` is actually
        // declared, not from the scanned leaf class: Entity/Taxonomy/Preset/
        // Presettable/User are Core base classes with a table every module
        // extends (final on all but User, which nothing overrides in
        // practice), so several concrete subclasses across CMS/ERP legitimately
        // share one physical table. Attributing the setting to the leaf class's
        // own module would let two modules fight over the same setting's
        // ownership stamp across separate reconcile() calls, and
        // SettingsCleaner deletes rows whose module is disabled/absent - so the
        // "wrong" module winning is a real data-loss risk, not just cosmetic.
        /** @var array<string, list<array<string,mixed>>> $settings_by_module */
        $settings_by_module = ['Core' => $core_settings];

        /** @var array<string, list<class-string<Model>>> $setting_provenance */
        $setting_provenance = [];

        foreach ($capabilities as $capability) {
            $module = $this->moduleForModel($this->tableOwningClass($capability->modelClass));
            $settings_by_module[$module] ??= [];

            $before = count($settings_by_module[$module]);
            $this->pushCapabilitySettings($settings_by_module[$module], $capability, $default_approval_threshold);

            for ($i = $before, $count = count($settings_by_module[$module]); $i < $count; $i++) {
                $setting_provenance[$settings_by_module[$module][$i]['name']][] = $capability->modelClass;
            }
        }

        $created = 0;
        $realigned = 0;
        $unchanged = 0;

        foreach ($settings_by_module as $module => $rows) {
            if ($rows === []) {
                continue;
            }

            $rows = $this->deduplicateSharedTableRows($rows, $setting_provenance, $module);

            $outcome = app(SeedReconciler::class)->reconcile(
                self::internalSettingsDefinition($module, $rows),
            );

            $created += count($outcome->created);
            $realigned += count($outcome->realigned);
            $unchanged += $outcome->unchanged;
        }

        $this->command?->line("    - created {$created}, realigned {$realigned}, unchanged {$unchanged}");
    }

    /**
     * Resolve the module that owns a model class, so its derived settings are
     * stamped with that module rather than with the module running this seeder.
     *
     * `Modules\{Name}\Models\...` owns `{Name}`; everything else (notably
     * `App\Models\...`) is treated as Core-owned.
     */
    private function moduleForModel(string $modelClass): string
    {
        $namespace = config('modules.namespace');
        $prefix = (is_string($namespace) ? $namespace : 'Modules') . '\\';

        if (! str_starts_with($modelClass, $prefix)) {
            return 'Core';
        }

        $module_name = strtok(mb_substr($modelClass, mb_strlen($prefix)), '\\');

        return $module_name === false || $module_name === '' ? 'Core' : $module_name;
    }

    /**
     * Resolve the class that actually declares the model's `$table` property,
     * so a shared-table hierarchy is attributed to the module owning the base
     * class rather than to whichever leaf subclass was scanned.
     *
     * Falls back to the model itself when `$table` is declared on the model
     * (the common case) or never declared at all (Eloquent infers the table
     * name by convention) - both resolve identically to before this method
     * existed.
     *
     * @param  class-string<Model>  $modelClass
     * @return class-string<Model>
     */
    private function tableOwningClass(string $modelClass): string
    {
        $declaring_class = new ReflectionClass($modelClass)->getProperty('table')->getDeclaringClass()->getName();

        /** @var class-string<Model> $owner */
        return $declaring_class === Model::class ? $modelClass : $declaring_class;
    }

    /**
     * Collapse rows that share an identity value after {@see tableOwningClass()}
     * grouped several model classes under the same module, logging the
     * collision instead of silently keeping whichever row happened to be
     * scanned last.
     *
     * Which row survives never changes what gets persisted: every field in a
     * derived-setting row comes only from the table name and global config
     * (default approval threshold), never from the model class itself, so
     * colliding rows for the same name are always byte-identical. Only the log
     * entry - naming the setting and the competing classes - carries
     * information the dedup would otherwise discard.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string, list<class-string<Model>>>  $provenance
     * @return list<array<string,mixed>>
     */
    private function deduplicateSharedTableRows(array $rows, array $provenance, string $module): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($rows as $row) {
            $name = (string) $row['name'];

            if (isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $deduplicated[] = $row;

            $classes = $provenance[$name] ?? [];

            if (count($classes) > 1) {
                Log::warning('Seeding: multiple model classes share a table-derived setting name', [
                    'setting' => $name,
                    'module' => $module,
                    'model_classes' => $classes,
                ]);
            }
        }

        return $deduplicated;
    }

    /**
     * Append the per-model rows a single {@see ModelCapabilities} entry contributes.
     */
    private function pushCapabilitySettings(array &$defaultSettings, ModelCapabilities $capability, int $defaultApprovalThreshold): void
    {
        $table = $capability->table;
        $instance = new ReflectionClass($capability->modelClass)->newInstanceWithoutConstructor();

        if ($capability->hasVersions) {
            $this->seedVersionedModel($defaultSettings, $instance, $table, $this->getSettingKeyName(self::VERSIONING_NAME_PREFIX, $table));
        }

        if ($capability->hasSoftDeletes) {
            $this->seedSoftDeletedModel($defaultSettings, $instance, $table, $this->getSettingKeyName(self::SOFT_DELETES_NAME_PREFIX, $table));
        }

        if ($capability->hasLocks) {
            $this->seedLockedModel($defaultSettings, $instance, $table, $this->getSettingKeyName(self::LOCK_NAME_PREFIX, $table));
        }

        if ($capability->hasOptimisticLocking) {
            $this->seedOptimisticLockedModel($defaultSettings, $instance, $table, $this->getSettingKeyName(self::OPTIMISTIC_LOCK_NAME_PREFIX, $table));
        }

        if ($capability->hasTranslations) {
            $this->seedTranslationFallbackModel($defaultSettings, $instance, $table, $this->getSettingKeyName(self::TRANSLATION_FALLBACK_NAME_PREFIX, $table));
            $this->seedAutoTranslateModel($defaultSettings, $instance, $table, $this->getSettingKeyName(self::AUTO_TRANSLATE_NAME_PREFIX, $table));
        }

        if ($capability->hasApprovals) {
            $defaultSettings[] = [
                'name' => $this->getSettingKeyName(self::APPROVAL_THRESHOLD_NAME_PREFIX, $table),
                'value' => $defaultApprovalThreshold,
                'encrypted' => false,
                'choices' => null,
                'type' => SettingTypeEnum::Integer,
                'group_name' => 'notifications',
                'description' => "Hours before notification for pending {$table} approvals",
            ];
        }
    }

    private function getSettingKeyName(string $prefix, string $suffix): string
    {
        return PerModelSettingResolver::nameFor($prefix, $suffix);
    }

    private function seedVersionedModel(array &$defaultSettings, Model $model, string $table, string $keyName): void
    {
        if (property_exists($model, 'versionStrategy')) {
            return;
        }

        $defaultSettings[] = [
            'name' => $keyName,
            'value' => VersionStrategy::DIFF,
            'encrypted' => false,
            'choices' => [false, ...VersionStrategy::cases()],
            'type' => SettingTypeEnum::Json,
            'group_name' => 'versioning',
            'description' => "Version strategy for {$table}",
        ];
    }

    private function seedSoftDeletedModel(array &$defaultSettings, Model $model, string $table, string $keyName): void
    {
        if (property_exists($model, 'softDeletesEnabled')) {
            return;
        }

        $defaultSettings[] = [
            'name' => $keyName,
            'value' => true,
            'encrypted' => false,
            'choices' => null,
            'type' => SettingTypeEnum::Boolean,
            'group_name' => 'soft_deletes',
            'description' => "Soft deletes status for {$table}",
        ];
    }

    private function seedLockedModel(array &$defaultSettings, Model $model, string $table, string $keyName): void
    {
        if (property_exists($model, 'locksEnabled')) {
            return;
        }

        $defaultSettings[] = [
            'name' => $keyName,
            'value' => true,
            'encrypted' => false,
            'choices' => null,
            'type' => SettingTypeEnum::Boolean,
            'group_name' => 'locking',
            'description' => "Lock status for {$table}",
        ];
    }

    private function seedOptimisticLockedModel(array &$defaultSettings, Model $model, string $table, string $keyName): void
    {
        if (property_exists($model, 'optimisticLocksEnabled')) {
            return;
        }

        $defaultSettings[] = [
            'name' => $keyName,
            'value' => true,
            'encrypted' => false,
            'choices' => null,
            'type' => SettingTypeEnum::Boolean,
            'group_name' => 'locking',
            'description' => "Optimistic lock status for {$table}",
        ];
    }

    private function seedTranslationFallbackModel(array &$defaultSettings, Model $model, string $table, string $keyName): void
    {
        if (property_exists($model, 'translation_fallback_enabled')) {
            return;
        }

        $defaultSettings[] = [
            'name' => $keyName,
            'value' => true,
            'encrypted' => false,
            'choices' => null,
            'type' => SettingTypeEnum::Boolean,
            'group_name' => 'translations',
            'description' => "Translation fallback for {$table}",
        ];
    }

    private function seedAutoTranslateModel(array &$defaultSettings, Model $model, string $table, string $keyName): void
    {
        if (property_exists($model, 'auto_translate_enabled')) {
            return;
        }

        $defaultSettings[] = [
            'name' => $keyName,
            'value' => false,
            'encrypted' => false,
            'choices' => null,
            'type' => SettingTypeEnum::Boolean,
            'group_name' => 'translations',
            'description' => "Auto-translate for {$table}",
        ];
    }

    private function defaultCrons(): void
    {
        $this->logOperation(CronJob::class);
        $cron_job_model = new CronJob;

        $default_crons = [
            [
                'name' => 'clearUserAssignedLicenses',
                'command' => 'auth:clear-licenses',
                'parameters' => [],
                'schedule' => '@midnight',
                'description' => 'Resetta assegnazione licenze login a utenti',
                'is_active' => (bool) config('core.auth.licenses.enabled', false),
            ],
            [
                'name' => 'clearResetTokens',
                'command' => 'auth:clear-resets',
                'parameters' => [],
                'schedule' => '*/4 * * * *',
                'description' => 'Rimuove reset password tokens scaduti',
                'is_active' => true,
            ],
            [
                'name' => 'checkPendingApprovals',
                'command' => 'approvals:check-pending',
                'parameters' => [],
                'schedule' => '0 */4 * * *',
                'description' => 'Controlla e notifica record in attesa di approvazione',
                'is_active' => false,
            ],
        ];

        $existing_crons = CronJob::query()->withoutGlobalScopes()
            ->pluck('name')
            ->flip()
            ->all();

        $new_crons = array_filter(
            $default_crons,
            fn ($cron) => ! isset($existing_crons[$cron['name']]),
        );

        if ($new_crons === []) {
            $this->command?->line('    - nothing to update');

            return;
        }

        $cron_job_model->getConnection()->transaction(function () use ($new_crons): void {
            foreach ($new_crons as &$cron) {
                if (! CronJob::query()->withoutGlobalScopes()->where('name', $cron['name'])->exists()) {
                    $this->create(CronJob::class, $cron);
                    $this->command?->line("    - {$cron['name']} <fg=green>created</>");
                } else {
                    $this->command?->line("    - {$cron['name']} already exists");
                }
            }
        });
    }
}
