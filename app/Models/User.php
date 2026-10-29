<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Carbon\CarbonInterface;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Support\HasOnceHash;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Lab404\Impersonate\Exceptions\InvalidUserProvider;
use Lab404\Impersonate\Exceptions\MissingUserProvider;
use Lab404\Impersonate\Models\Impersonate;
use Lab404\Impersonate\Services\ImpersonateManager;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Authorization\ResolvingAuthorization;
use Modules\Core\Casts\ActionEnum;
use Modules\Core\Contracts\ILockableModel;
use Modules\Core\Contracts\ISoftDeletableModel;
use Modules\Core\Contracts\IValidatableModel;
use Modules\Core\Database\Factories\UserFactory;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Locking\Traits\HasLocks;
use Modules\Core\Models\Concerns\HasValidations;
use Modules\Core\Models\Concerns\HasValidity;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\Core\Models\Pivot\ModelHasRole;
use Modules\Core\Observers\UserObserver;
use Modules\Core\Services\ModificationVoteService;
use Modules\Core\SoftDeletes\SoftDeletes;
use Modules\Core\Support\PermissionName;
use Override;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use UnexpectedValueException;

#[ObservedBy([UserObserver::class])]
class User extends BaseUser implements FilamentUser, HasLocalePreference, HasOnceHash, ILockableModel, ISoftDeletableModel, IValidatableModel, MustVerifyEmail, PasskeyUser
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasLocks;
    use HasRoles {
        getPermissionsViaRoles as private getPermissionsViaRolesTrait;
        roles as private rolesTrait;
    }
    use HasValidations {
        getRules as private getRulesTrait;
    }
    use HasValidity;
    use HasVersions;
    use Impersonate {
        isImpersonated as private isImpersonatedTrait;
    }
    use Notifiable;
    use PasskeyAuthenticatable;
    use SoftDeletes {
        HasVersions::delete insteadof SoftDeletes;
        HasVersions::forceDelete insteadof SoftDeletes;
        SoftDeletes::delete as protected deleteWithTheBin;
        SoftDeletes::forceDelete as protected forceDeleteWithTheBin;
    }
    use TwoFactorAuthenticatable;

    #[Override]
    protected $table = CoreTables::Users->value;

    /**
     * Default attribute values, so a fresh or partially-hydrated instance always
     * exposes the onboarding flag and preferences bag (strict attribute access).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_first_login' => true,
        'is_service_account' => false,
        'preferences' => null,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     *
     * @psalm-suppress NonInvariantDocblockPropertyType
     */
    #[Override]
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'lang',
        'email_verified_at',
        'license_id',
        'social_id',
        'social_service',
        'social_token',
        'social_refresh_token',
        'social_token_secret',
        'preferences',
        'is_service_account',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     *
     * @psalm-suppress NonInvariantDocblockPropertyType
     */
    #[Override]
    protected $hidden = [
        'password',
        'remember_token',
        'pivot',
        'license_id',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'last_login_at',
        'email_verified_at',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->dontVersionable = array_merge($this->dontVersionable, ['password', 'remember_token']);
    }

    /**
     * Guard names for Spatie permission resolution on this user instance.
     *
     * Auth providers often reference {@see \App\Models\User} while tests or modules
     * instantiate {@see self} directly; include every guard whose provider model is
     * this class or a subclass so role assignment and checks stay consistent.
     *
     * @return list<string>
     */
    public function guardName(): array
    {
        $guards = config('auth.guards', []);

        if (! is_array($guards)) {
            $guards = [];
        }

        $guard_names = collect($guards)
            ->keys()
            ->filter(function (string $guard_name): bool {
                $provider = config("auth.guards.{$guard_name}.provider");

                if (! is_string($provider)) {
                    return false;
                }

                $model = config("auth.providers.{$provider}.model");

                if (! is_string($model) || ! class_exists($model)) {
                    return false;
                }

                return is_a(static::class, $model, true) || is_a($model, static::class, true);
            })
            ->values()
            ->all();

        if ($guard_names !== []) {
            return $guard_names;
        }

        $default_guard = config('auth.defaults.guard', 'web');

        return [is_string($default_guard) ? $default_guard : 'web'];
    }

    /**
     * Key `once()` memoization on the user identity rather than the object handle.
     *
     * PHP reuses an object handle after the previous object is freed, so
     * spl_object_hash() — Onceable's default for captured objects — can make two
     * different users collide on one memoization key.
     *
     * Limitation: unsaved users have no key and therefore all share the `unsaved`
     * hash. Every caller today memoizes against a persisted user, so this is safe;
     * reuse in a context that memoizes unsaved users would need a different fallback.
     */
    public function onceHash(): string
    {
        $key = $this->getKey();

        return static::class . ':' . (is_scalar($key) ? (string) $key : 'unsaved');
    }

    /**
     * The language notifications and mailables are sent in: the one the user chose, the
     * same value the backoffice and the SPA are rendered in.
     */
    public function preferredLocale(): ?string
    {
        $lang = $this->attributes['lang'] ?? null;

        return is_string($lang) && $lang !== '' ? $lang : null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->isServiceAccount()) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->hasPermissionTo('*', $panel->getAuthGuard());
    }

    /**
     * Whether the account is non-interactive: no usable password, no sign-in, no panel, tokens only.
     */
    public function isServiceAccount(): bool
    {
        return (bool) ($this->attributes['is_service_account'] ?? false);
    }

    /**
     * Whether the user is the anonymous visitor.
     *
     * A user without an email counts as anonymous, but a service account never does: it is a named
     * principal that acts with its own roles and tokens, and treating it as a visitor would hide
     * attributes from it and skip the checks that apply to authenticated users. The `users.email`
     * column is not nullable, so this only matters for a service account built in memory; the
     * backoffice requires an email for it all the same.
     */
    public function isGuest(): bool
    {
        $guest = config('permission.users.guest');
        $has_guest_identity = is_string($guest) && $guest !== ''
            && in_array($guest, [$this->getAttribute('name'), $this->getAttribute('username')], true);

        // First: an account that carries the configured guest name fails safe as a guest, flagged as a
        // service account or not.
        if ($has_guest_identity) {
            return true;
        }

        if ($this->isServiceAccount()) {
            return false;
        }

        if ($this->getAttribute('email') === null) {
            return true;
        }

        if (! is_string($guest) || $guest === '') {
            throw new UnexpectedValueException('Configured guest account is invalid.');
        }

        return false;
    }

    public function isSuperAdmin(): bool
    {
        return $this->resolvingAuthorization(fn (): bool => $this->hasRole(config('permission.roles.superadmin')));
    }

    public function isAdmin(): bool
    {
        return $this->resolvingAuthorization(fn (): bool => $this->hasRole(config('permission.roles.admin')));
    }

    /**
     * Signing in is not an edit.
     *
     * A lock on an account stops anybody from restating who that user is, but the framework keeps
     * its own bookkeeping on the same row: the guard rotates `remember_token` on every login and
     * logout, and `last_login_at` records the session. Refusing those writes does not protect the
     * record, it only makes a locked user unable to sign in or out.
     *
     * @return list<string>
     */
    public function attributesWritableWhileLocked(): array
    {
        return ['remember_token', 'last_login_at'];
    }

    /**
     * Whether the account may sign in at the given moment.
     * Accounts with both validity columns unset are always allowed (legacy behaviour).
     */
    public function canAuthenticate(?CarbonInterface $at = null): bool
    {
        if ($this->valid_from === null && $this->valid_to === null) {
            return true;
        }

        if ($this->valid_from === null) {
            return false;
        }

        return $this->isValid($at ?? Date::now());
    }

    /**
     * Whether the current session is impersonating a user.
     *
     * Typed redeclaration of the trait method, which declares no return type.
     * The state is session-backed, not a property of this row: it answers
     * "is this request an impersonation" and is therefore identical for every
     * user instance, and false wherever there is no session (console, queue).
     */
    public function isImpersonated(): bool
    {
        return $this->isImpersonatedTrait();
    }

    public function canImpersonate(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        try {
            return $this->hasPermissionViaRole(Permission::findByName(PermissionName::forModel($this, ActionEnum::Impersonate->value)));
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    public function canBeImpersonated(): bool
    {
        return ! $this->isSuperAdmin() && ! $this->isServiceAccount();
    }

    /**
     * returns the impersonator user.
     *
     * @throws BindingResolutionException
     * @throws MissingUserProvider
     * @throws InvalidUserProvider
     * @throws ModelNotFoundException
     */
    public function getImpersonator(): self
    {
        return $this->isImpersonated() ? resolve(ImpersonateManager::class)->getImpersonator() : $this;
    }

    /**
     * returns the license currently related to the user.
     *
     * @return BelongsTo<License, $this>
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /**
     * The personal access tokens. Declared with its relation type, which Sanctum leaves to PHPDoc: a relation a
     * request names is called only when its declaration says it returns a relation.
     *
     * @return MorphMany<PersonalAccessToken, $this>
     */
    public function tokens(): MorphMany
    {
        return $this->morphMany(Sanctum::$personalAccessTokenModel, 'tokenable');
    }

    /**
     * @return MorphMany<Notification, $this>
     */
    public function readNotifications(): MorphMany
    {
        return $this->notifications()->read();
    }

    /**
     * @return MorphMany<Notification, $this>
     */
    public function unreadNotifications(): MorphMany
    {
        return $this->notifications()->unread();
    }

    /**
     * @return BelongsToMany<Role, $this, ModelHasRole>
     */
    public function roles(): BelongsToMany
    {
        return $this->rolesTrait()->using(ModelHasRole::class);
    }

    /**
     * Whether the user holds a permission by any route at all.
     *
     * Three routes exist and all three must count: granted straight to the user, granted to one of
     * their roles, or granted to an **ancestor** of one of their roles. Spatie's `hasPermissionTo`
     * covers the first two only: it asks whether the user holds one of the roles attached to the
     * permission, so a permission sitting on a parent role is invisible to it even though
     * {@see Role::hasPermission()} and {@see Role::getAllPermissions()} both report it. Role
     * inheritance stopped one step short of the user, and this closes that step.
     *
     * Every route is evaluated on one guard: `null` is the guard of the current request
     * ({@see Auth::getDefaultDriver()}), and a permission that does not exist on it is not held.
     * A missing permission on the direct path means no role can hold it on that guard either,
     * so the role route is skipped.
     */
    public function hasPermission(string $permission, ?string $guard_name = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $guard_name ??= Auth::getDefaultDriver();

        return $this->resolvingAuthorization(function () use ($permission, $guard_name): bool {
            try {
                if ($this->hasPermissionTo($permission, $guard_name)) {
                    return true;
                }
            } catch (PermissionDoesNotExist) {
                return false;
            }

            return $this->roles->contains(static fn (Role $role): bool => $role->hasPermission($permission, $guard_name));
        });
    }

    /**
     * A superadmin holds every permission. They come from the permission registrar's cache,
     * which Spatie flushes on every permission and role write, instead of a query per call.
     */
    public function getPermissionsViaRoles(): Collection
    {
        if ($this->isSuperAdmin()) {
            return app(PermissionRegistrar::class)->getPermissions()->sortBy('name')->values();
        }

        return $this->getPermissionsViaRolesTrait();
    }

    /**
     * @return array<string, mixed>
     */
    public function getRules(): array
    {
        $rules = $this->getRulesTrait();

        // Rules for properties that could use attributes in the future
        // Note: name, email, password are defined in parent class, so attributes
        // would need to be added there or we use array-based rules for now
        $rules[self::DEFAULT_RULE] = array_merge($rules[self::DEFAULT_RULE], [
            'lang' => ['nullable', 'in:' . implode(',', config('app.available_locales', translations()))],
            'locked_at' => ['nullable', 'date'],
        ]);

        $user_class = CoreTables::Users->value;

        // Create rules - complex rules (unique with closure) must stay in array format
        $rules['create'] = array_merge($rules['create'], [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'max:255',
                /** @var \Illuminate\Database\Query\Builder $query */
                Rule::unique($user_class)->where(function ($query): void { // @pest-ignore-type
                    $query->where('deleted_at', null);
                }),
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique($user_class),
            ],
            'password' => [Password::required()],
        ]);

        // Update rules - complex rules (unique with closure and ignore) must stay in array format
        $rules['update'] = array_merge($rules['update'], [
            'name' => ['nullable', 'string', 'max:255'],
            'username' => [
                'nullable',
                'string',
                'max:255',
                /** @var \Illuminate\Database\Query\Builder $query */
                Rule::unique($user_class)->where(function ($query): void { // @pest-ignore-type
                    $query->where('deleted_at', null);
                })->ignore($this->id, 'id'),
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                /** @var \Illuminate\Database\Query\Builder $query */
                Rule::unique($user_class)->where(function ($query): void { // @pest-ignore-type
                    $query->where('deleted_at', null);
                })->ignore($this->id, 'id'),
            ],
            'password' => ['nullable', Password::default()],
        ]);

        return $rules;
    }

    /**
     * @return MorphMany<Approval, $this>
     */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approver');
    }

    /**
     * @return MorphMany<Disapproval, $this>
     */
    public function disapprovals(): MorphMany
    {
        return $this->morphMany(Disapproval::class, 'disapprover');
    }

    /**
     * Override the Notifiable relation so database notifications are read and
     * written through {@see Notification}, which mirrors the module scope into a
     * queryable `module_name` column.
     *
     * @return MorphMany<Notification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable')->latest();
    }

    /**
     * Run the authorization hook before a connection-scoped vote is cast.
     */
    public function isAuthorizedToCastApprovalVote(Modification $modification, bool $approval): bool
    {
        return $approval
            ? $this->authorizedToApprove($modification)
            : $this->authorizedToDisapprove($modification);
    }

    /**
     * Cast a vote in favour. Every caller goes through ModificationVoteService, which owns the
     * vote rules, the transaction and the application of a completed decision.
     *
     * Replaces the identically named method of the laravel-approval ApprovesChanges trait,
     * which recorded and applied the vote itself.
     *
     * @return bool false when this user may not cast the vote
     */
    public function approve(Modification $modification, ?string $reason = null): bool
    {
        return resolve(ModificationVoteService::class)->cast($this, $modification, true, $reason);
    }

    /**
     * @return bool false when this user may not cast the vote
     */
    public function disapprove(Modification $modification, ?string $reason = null): bool
    {
        return resolve(ModificationVoteService::class)->cast($this, $modification, false, $reason);
    }

    protected static function newFactory(): UserFactory
    {
        UserFactory::guessModelNamesUsing(static fn (): string => static::class);

        return UserFactory::new();
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    #[Scope]
    protected static function superAdmin(Builder $query): Builder
    {
        return $query->whereHas('roles', static fn ($query) => $query->where('name', config('permission.roles.superadmin'))); // @pest-ignore-type
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    #[Scope]
    protected static function admin(Builder $query): Builder
    {
        return $query->whereHas('roles', static fn ($query) => $query->where('name', config('permission.roles.admin'))); // @pest-ignore-type
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'password' => 'hashed',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'datetime',
            'is_first_login' => 'boolean',
            'is_service_account' => 'boolean',
            'preferences' => 'array',
        ];
    }

    protected function authorizedToApprove(Modification $mod): bool
    {
        if ($this->isModificationAuthor($mod)) {
            return false;
        }

        return $this->can($this->approvalPermissionFor($mod, 'approve'));
    }

    /**
     * Disapproving needs no permission of its own: `approve` governs both votes, and
     * `ActionEnum` has never declared a `disapprove` ability.
     */
    protected function authorizedToDisapprove(Modification $mod): bool
    {
        if ($this->isModificationAuthor($mod)) {
            return false;
        }

        return $this->can($this->approvalPermissionFor($mod, 'approve'));
    }

    protected function getDefaultGuardName(): string
    {
        return 'web';
    }

    /**
     * Deletes through the bin inside the revision scope opened by {@see HasVersions::delete()}.
     */
    protected function deleteRecord(): ?bool
    {
        return $this->deleteWithTheBin();
    }

    /**
     * Force-deletes through the bin inside the revision scope opened by {@see HasVersions::forceDelete()}.
     */
    protected function forceDeleteRecord(): ?bool
    {
        return $this->forceDeleteWithTheBin();
    }

    /**
     * Read this user's own roles and permissions without the per-row select guard biting.
     *
     * Those rows live in tables that carry the guard themselves, so asking what somebody may do
     * would require already knowing it. Reading your own authorization data is not a business read
     * of the roles table, and the table-level check on that table still stands for callers that
     * really are listing roles.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function resolvingAuthorization(callable $callback): mixed
    {
        return ResolvingAuthorization::resolve($callback);
    }

    private function isModificationAuthor(Modification $mod): bool
    {
        if ($mod->modifier_id === null || $mod->modifier_type === null) {
            return false;
        }

        return (string) $mod->modifier_id === (string) $this->getKey()
            && $mod->modifier_type === static::class;
    }

    private function approvalPermissionFor(Modification $mod, string $action): string
    {
        $table = $mod->modifiable?->getTable();

        if ($table === null && is_string($mod->modifiable_type) && class_exists($mod->modifiable_type)) {
            $table = (new $mod->modifiable_type())->getTable();
        }

        $connection = $mod->modifiable?->getConnectionName()
            ?? $mod->getConnectionName()
            ?? 'default';

        return PermissionName::build((string) $connection, (string) $table, $action);
    }
}
