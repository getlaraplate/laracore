<?php

declare(strict_types=1);

namespace Modules\Core\Models\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use LogicException;
use Modules\Core\Authorization\PartOwnerResolver;
use Modules\Core\Authorization\PermissionExistenceMemo;
use Modules\Core\Authorization\ResolvingAuthorization;
use Modules\Core\Authorization\RetrievedSelectGuard;
use Modules\Core\Casts\CrudExecutor;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Models\User;
use Modules\Core\Overrides\ContextualValidationException;
use Modules\Core\Overrides\ContextualValidator;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Support\PermissionName;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Spatie\Permission\Contracts\Permission as SpatiePermission;
use Spatie\Permission\Contracts\Role as SpatieRole;

/**
 * Trait per aggiungere validazioni ai modelli.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 *
 * @method void setSkipValidation(bool $skip = true) Imposta il flag per saltare le validazioni
 * @method bool shouldSkipValidation() Verifica se le validazioni devono essere saltate
 * @method array getRules() Ottiene le regole di validazione
 * @method array getOperationRules(?string $operation = null) Ottiene le regole di validazione per un'operazione specifica
 * @method void validateWithRules(string $operation) Valida il modello con le regole
 *
 * @phpstan-type HasValidationsType HasValidations
 */
trait HasValidations
{
    public const DEFAULT_RULE = 'always';

    /**
     * Validation rules per operation: 'create', 'update' and the DEFAULT_RULE
     * bucket that applies to both. A rule is whatever Validator accepts, which is
     * a string, an array of them, or a Rule object.
     *
     * @var array<string, array<string, mixed>>
     */
    protected $rules = [
        'create' => [],
        'update' => [],
        // 'always' => [],
    ];

    /**
     * Flag per saltare le validazioni.
     */
    protected bool $skip_validation = false;

    /**
     * Imposta il flag per saltare le validazioni.
     */
    public function setSkipValidation(bool $skip = true): void
    {
        $this->skip_validation = $skip;
    }

    /**
     * Verifica se le validazioni devono essere saltate.
     */
    public function shouldSkipValidation(): bool
    {
        return $this->skip_validation;
    }

    /**
     * Returns the model attributes used for validation.
     * Can be overridden in concrete classes or other traits (e.g. HasPlace)
     * to include additional virtual attributes not stored directly on the model.
     *
     * @return array<string, mixed>
     */
    public function getAttributesForValidation(): array
    {
        $attributes = $this->getAttributes();

        foreach ($this->getCasts() as $key => $cast) {
            if (! array_key_exists($key, $attributes) || ! $this->shouldUseCastedAttributeForValidation($cast)) {
                continue;
            }

            $attributes[$key] = $this->getAttribute($key);
        }

        return $attributes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getRules(): array
    {
        $primary_key = $this->getKeyName();
        $rules = $this->rules;

        if (! isset($rules[self::DEFAULT_RULE])) {
            $rules[self::DEFAULT_RULE] = [];
        }

        $rules['update'] = array_merge($rules['update'], [
            $primary_key => 'required|exists:' . $this->getTable() . ',' . $primary_key,
        ]);

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOperationRules(?string $operation = null): array
    {
        $rules = $this->getRules();
        $operation = $this->normalizeValidationOperation($operation);

        $operation_rules = $operation && array_key_exists($operation, $rules)
            ? array_merge($rules[self::DEFAULT_RULE] ?? [], $rules[$operation])
            : $rules[self::DEFAULT_RULE] ?? [];

        return $this->qualifyDatabaseValidationRules($operation_rules);
    }

    public function validateWithRules(string $operation): void
    {
        if ($this->shouldSkipValidation()) {
            return;
        }

        $rules = $this->getOperationRules($operation);

        if ($rules !== []) {
            $attributes = $this->getAttributesForValidation();
            $attributes = $this->prepareJsonRuleAttributes($attributes, $rules);

            if (class_uses_trait($this, HasDynamicContents::class)) {
                /** @phpstan-ignore method.notFound */
                $components = $this->getComponentsAttribute();
                $components = $this->prepareJsonRuleAttributes($components, $rules);

                $attributes = array_merge($attributes, $components);

                $shared_components = $this->attributes['shared_components'] ?? null;

                if (is_string($shared_components)) {
                    $shared = json_decode($shared_components, true);
                    $attributes = array_merge($attributes, is_array($shared) ? $shared : []);
                }
            }

            $validator = Validator::make($attributes, $rules);

            if ($validator instanceof ContextualValidator) {
                $validator->withLogContext([
                    'entity' => $this->getTable(),
                    'model' => static::class,
                    'operation' => $operation,
                    'id' => $this->getKey(),
                ]);
            }

            $validator->setException(ContextualValidationException::class);
            $validator->validate();
        }
    }

    protected static function bootHasValidations(): void
    {
        // There is no pre-query event, so the select authorization is verified per
        // hydrated row here — except when a caller that already authorized the select
        // for this exact class (e.g. the CRUD engine, which ensures the table-level
        // permission and applies row-level ACL filters before hydrating) suppresses
        // the now-redundant per-row check for that class only.
        static::retrieved(function (Model $model): void {
            if (RetrievedSelectGuard::isSuppressed($model::class)) {
                return;
            }

            // Roles and permissions are what an authorization answer is made of, so reading one
            // cannot itself require an answer: deciding whether somebody may read a role means
            // reading their roles. Eloquent does not reload a relation that is already loading, so
            // the circle does not merely recurse, it hands back a null relation and breaks.
            // Callers that genuinely list these tables are still stopped by the table-level check
            // the CRUD read engine runs before hydrating anything.
            if ($model instanceof SpatieRole || $model instanceof SpatiePermission) {
                return;
            }

            if (! static::checkUserCanDo($model, 'select')) {
                throw new AuthorizationException(static::selectRefusalMessage($model));
            }
        });
        static::creating(function (Model $model): void {
            /** @var self $model */
            throw_unless(static::checkUserCanDo($model, 'insert'), AuthorizationException::class, 'User cannot insert ' . $model->getTable());

            if (! $model->shouldSkipValidation()) {
                $model->validateWithRules(CrudExecutor::INSERT);
            }
        });
        static::updating(function (Model $model): void {
            /** @var self $model */
            throw_unless(static::checkUserCanDo($model, 'update'), AuthorizationException::class, 'User cannot update ' . $model->getTable());

            if (! $model->isDirty('deleted_at') && ! $model->shouldSkipValidation()) {
                $model->validateWithRules(CrudExecutor::UPDATE);
            }
        });
        static::deleting(function (Model $model): void {
            throw_if((! method_exists($model, 'isForceDeleting') || $model->isForceDeleting()) && ! static::checkUserCanDo($model, 'forceDelete'), AuthorizationException::class, 'User cannot delete ' . $model->getTable());

            throw_unless(static::checkUserCanDo($model, 'delete'), AuthorizationException::class, 'User cannot soft delete ' . $model->getTable());
        });

        if (method_exists(static::class, 'forceDeleting')) {
            static::forceDeleting(function (Model $model): void {
                throw_unless(static::checkUserCanDo($model, 'forceDelete'), AuthorizationException::class, 'User cannot delete ' . $model->getTable());
            });
        }

        if (method_exists(static::class, 'restoring')) {
            static::restoring(function (Model $model): void {
                throw_unless(static::checkUserCanDo($model, 'restore'), AuthorizationException::class, 'User cannot restore ' . $model->getTable());
            });
        }
    }

    protected static function checkUserCanDo(Model $model, string $operation): bool
    {
        // Both questions below read the acting user's roles and permissions, and those models
        // carry this same guard. Asking whether the user may read them requires knowing what the
        // user is allowed to do, which is what is being worked out. The machinery does not
        // authorize itself, so a read taken inside an answer is part of that answer.
        if (ResolvingAuthorization::inProgress()) {
            return true;
        }

        $permissions = static::governingPermissions($model, $operation);

        if ($permissions === []) {
            return true;
        }

        // Resolving the acting user hydrates a User, which fires this same check: the lookup must
        // sit inside the window too, or it re-enters itself until the stack is exhausted.
        $user = ResolvingAuthorization::resolve(static fn (): ?Authenticatable => Auth::user());

        if ($user) {
            // Hydrating the acting user from the session is how authentication itself works, so a
            // user always reads their own row, whatever they were granted.
            if (static::isActingUserRow($user, $model)) {
                return true;
            }

            // Asked as the CRUD asks it, on the request's guard and within the abilities of the token that
            // authenticated the request: the Gate reads neither, so an `api` role went unseen and a token's
            // abilities did not narrow what its owner's roles grant.
            return ResolvingAuthorization::resolve(static function () use ($user, $permissions): bool {
                foreach (array_keys($permissions) as $permission) {
                    $holds = $user instanceof User
                        ? resolve(AuthorizationService::class)->userHolds($user, $permission)
                        : $user->can($permission);

                    if ($holds) {
                        return true;
                    }
                }

                return false;
            });
        }

        return true;
    }

    /**
     * Whether the record is the acting user's own row. Only a row of the user's table that carries its key can be:
     * a grouped aggregate or a partial select hydrates rows without one, and reading the missing key would throw.
     */
    protected static function isActingUserRow(Authenticatable $user, Model $model): bool
    {
        if (! $user instanceof Model || $model->getTable() !== $user->getTable()) {
            return false;
        }

        if (! array_key_exists($model->getKeyName(), $model->getAttributes())) {
            return false;
        }

        return $user->is($model);
    }

    /**
     * The registered permissions any one of which allows the operation on the record, each mapped to the table it
     * names, the record's own first. Empty when none is registered: the operation is then not governed by a
     * permission.
     *
     * Writes ask the record's own permission. A read of a part of its parent ({@see IsPartOfParent}) also accepts
     * the `select` of every model up the part's chain ({@see PartOwnerResolver}), nearest first: each intermediate
     * part and the first owner that is not a part. The part inherits the visibility of the record it is loaded from
     * (spec 8.1), and the relation path loads it from any step of that chain, so a caller who reads any of them
     * hydrates the part whatever the part's own permission says, while a caller holding the part's own `select`
     * still reads it from its own endpoint. A model of the chain without a registered permission adds nothing, and
     * a chain that does not resolve leaves the part's own permission alone in charge.
     *
     * @return array<string, string>
     */
    protected static function governingPermissions(Model $model, string $operation): array
    {
        /** @var class-string<Model> $permission_class */
        $permission_class = config('permission.models.permission');

        // Model events fire on the write path, so the name must match exactly what
        // `permission:refresh` registers: `{connection}.{table}.{operation}`, with
        // `default` standing in for models that follow `database.default`. Dropping
        // the connection segment made every lookup miss, and a miss reads as
        // "permission not registered" -> operation allowed.
        // Existence is memoized for the life of the process (PermissionExistenceMemo), not per
        // request: PHP-FPM resets it with every request, but a queue worker keeps its answers until
        // it restarts, so workers must be restarted after a deploy that registers permissions.
        $permissions = [];
        $own = PermissionName::forModel($model, $operation);

        if (PermissionExistenceMemo::exists($permission_class, $own)) {
            $permissions[$own] = $model->getTable();
        }

        $chain = $operation === 'select' ? PartOwnerResolver::chainClassesOf($model) : null;

        foreach ($chain ?? [] as $ancestor_class) {
            $ancestor = new ReflectionClass($ancestor_class)->newInstanceWithoutConstructor();
            $name = PermissionName::forModel($ancestor, 'select');

            if (PermissionExistenceMemo::exists($permission_class, $name)) {
                $permissions[$name] = $ancestor->getTable();
            }
        }

        return $permissions;
    }

    /**
     * Names what a refused read was missing: the tables whose `select` was checked, the record's own and those of
     * the owners up the chain of a part.
     */
    protected static function selectRefusalMessage(Model $model): string
    {
        $permissions = static::governingPermissions($model, 'select');
        $table = $model->getTable();
        $own = PermissionName::forModel($model, 'select');
        $owners = array_values(array_diff_key($permissions, [$own => true]));
        $noun = count($owners) > 1 ? 'owners' : 'owner';

        return match (true) {
            $owners === [] => 'User cannot select ' . $table,
            array_key_exists($own, $permissions) => sprintf('User cannot select %s nor its %s %s', $table, $noun, implode(', ', $owners)),
            default => sprintf('User cannot select %s, %s of %s', implode(', ', $owners), $noun, $table),
        };
    }

    /**
     * Whether a casted attribute should be passed to the validator using its cast value.
     */
    protected function shouldUseCastedAttributeForValidation(mixed $cast): bool
    {
        if (! is_string($cast)) {
            return false;
        }

        return in_array($cast, ['array', 'object', 'collection', 'encrypted:array'], true)
            || str_starts_with($cast, 'array:');
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function qualifyDatabaseValidationRules(array $rules): array
    {
        foreach ($rules as $attribute => $attribute_rules) {
            $rules[$attribute] = $this->qualifyDatabaseValidationRule($attribute_rules);
        }

        return $rules;
    }

    protected function qualifyDatabaseValidationRule(mixed $rule): mixed
    {
        if (is_array($rule)) {
            return array_map(fn (mixed $item): mixed => $this->qualifyDatabaseValidationRule($item), $rule);
        }

        if ($rule instanceof Exists || $rule instanceof Unique) {
            $rule = clone $rule;
            $this->replaceDatabaseRuleTable($rule, $this->qualifyDatabaseRuleTable($this->databaseRuleTable($rule)));

            return $rule;
        }

        if (! is_string($rule)) {
            return $rule;
        }

        return preg_replace_callback(
            '/(^|\\|)(exists|unique):([^|]+)/',
            fn (array $matches): string => $matches[1] . $matches[2] . ':' . $this->qualifyDatabaseRuleParameters($matches[3]),
            $rule,
        ) ?? $rule;
    }

    protected function qualifyDatabaseRuleParameters(string $parameters): string
    {
        $parameters = explode(',', $parameters);
        $parameters[0] = $this->qualifyDatabaseRuleTable($parameters[0]);

        return implode(',', $parameters);
    }

    protected function qualifyDatabaseRuleTable(string $table): string
    {
        if (str_contains($table, '.')) {
            return $table;
        }

        if (class_exists($table) && is_a($table, Model::class, true)) {
            /** @var Model $related */
            $related = new $table;

            return $related->getConnection()->getName() . '.' . $related->getTable();
        }

        return $this->getConnection()->getName() . '.' . $table;
    }

    /**
     * Laravel has no public setter for a database rule table; cloning preserves its callbacks and where clauses.
     */
    protected function databaseRuleTable(Exists|Unique $rule): string
    {
        try {
            if (! property_exists($rule, 'table')) {
                throw new LogicException(sprintf(
                    'Unsupported database validation rule [%s]: missing internal table property.',
                    $rule::class,
                ));
            }

            $table = (new ReflectionProperty($rule, 'table'))->getValue($rule);
        } catch (ReflectionException $exception) {
            throw new LogicException(sprintf(
                'Unsupported database validation rule [%s]: internal table property is inaccessible.',
                $rule::class,
            ), previous: $exception);
        }

        if (! is_string($table)) {
            throw new LogicException(sprintf(
                'Unsupported database validation rule [%s]: internal table property must be a string.',
                $rule::class,
            ));
        }

        return $table;
    }

    protected function replaceDatabaseRuleTable(Exists|Unique $rule, string $table): void
    {
        try {
            (new ReflectionProperty($rule, 'table'))->setValue($rule, $table);
        } catch (ReflectionException $exception) {
            throw new LogicException(sprintf(
                'Unsupported database validation rule [%s]: internal table property is inaccessible.',
                $rule::class,
            ), previous: $exception);
        }
    }

    /**
     * Maps legacy operation aliases to the rule-set keys used by models.
     */
    protected function normalizeValidationOperation(?string $operation): ?string
    {
        return match ($operation) {
            'save' => 'update',
            default => $operation,
        };
    }

    /**
     * Laravel's `json` rule only accepts JSON strings, not PHP arrays or objects.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function prepareJsonRuleAttributes(array $attributes, array $rules): array
    {
        foreach ($attributes as $key => $value) {
            if (! isset($rules[$key]) || ! $this->validationRulesContainJson($rules[$key])) {
                continue;
            }

            if (! is_array($value) && ! is_object($value)) {
                continue;
            }

            $attributes[$key] = json_encode($value);
        }

        return $attributes;
    }

    protected function validationRulesContainJson(mixed $rules): bool
    {
        if (is_string($rules)) {
            return str_contains($rules, 'json');
        }

        if (! is_array($rules)) {
            return false;
        }

        foreach ($rules as $rule) {
            if (is_string($rule) && $rule === 'json') {
                return true;
            }
        }

        return false;
    }
}
