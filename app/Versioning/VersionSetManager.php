<?php

declare(strict_types=1);

namespace Modules\Core\Versioning;

use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Modules\Core\Contracts\IOptimisticLockableModel;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Locking\Exceptions\StaleModelLockingException;
use Modules\Core\Models\VersionSet;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;
use Modules\Core\Versioning\Data\VersionSetOptions;
use Modules\Core\Versioning\Data\VersionSetRoot;
use Modules\Core\Versioning\Exceptions\DistinctVersionSetRootInstanceException;
use Modules\Core\Versioning\Exceptions\InvalidRevertedVersionSetException;
use Modules\Core\Versioning\Exceptions\MultipleVersionConnectionsNotSupportedException;
use Modules\Core\Versioning\Exceptions\VersionSetOptionsMismatchException;
use Override;

final class VersionSetManager implements VersionSetManagerInterface
{
    /**
     * The scope opened by the outermost run(), null outside one.
     */
    private ?VersionScope $scope = null;

    public function __construct(private readonly DatabaseManager $database) {}

    #[Override]
    public function run(
        VersionSetRoot $root,
        Closure $operation,
        ?VersionSetOptions $options = null,
    ): mixed {
        if ($this->scope instanceof VersionScope) {
            return $this->runNested($root, $operation, $options);
        }

        return $this->runOutermost($root, $operation, $options ?? new VersionSetOptions, VersionCaptureQuality::Atomic);
    }

    /**
     * Opens a scope for a change whose business statement already ran, as the writer does when no scope was
     * active. Its sets are atomic only when the application had a transaction open on the root's connection, so the
     * business statement and the history commit together; otherwise they are best effort (D47, D52).
     */
    #[Override]
    public function runAfterTheFact(VersionSetRoot $root, Closure $operation, VersionSetOptions $options): mixed
    {
        if ($this->scope instanceof VersionScope) {
            return $this->runNested($root, $operation, $options);
        }

        $capture = ApplicationTransactions::isOpenOn($root->model()->getConnection())
            ? VersionCaptureQuality::Atomic
            : VersionCaptureQuality::BestEffort;

        return $this->runOutermost($root, $operation, $options, $capture);
    }

    #[Override]
    public function setFor(Model $root): ActiveVersionSet
    {
        $version_root = VersionSetRoot::forModel($root);
        $existing = $this->activeScope()->find($version_root);

        if ($existing instanceof ActiveVersionSet) {
            return $existing;
        }

        $this->enlist($version_root->connectionName());

        // Not locked here: the write that brings this root's change already ran in the scope's transaction and holds
        // the row, and realigning the instance from inside its own model events would undo what they are doing.
        return $this->openSet(
            $version_root,
            new VersionSetOptions(actor: $this->activeScope()->outermost()->options()->actorId()),
            lock: false,
        );
    }

    #[Override]
    public function enlist(string $connection): void
    {
        $scope = $this->activeScope();
        $normalized = $this->database->connection($connection)->getName() ?? $connection;
        $enlisted = $scope->enlistedConnectionName();

        if ($enlisted !== null && $enlisted !== $normalized) {
            throw MultipleVersionConnectionsNotSupportedException::forConnections($enlisted, $normalized);
        }

        $scope->setEnlistedConnectionName($normalized);
    }

    #[Override]
    public function current(): ?ActiveVersionSet
    {
        return $this->scope?->current();
    }

    private function runOutermost(
        VersionSetRoot $root,
        Closure $operation,
        VersionSetOptions $resolved_options,
        VersionCaptureQuality $capture,
    ): mixed {
        $connection = $root->model()->getConnection();
        $connection_name = $root->connectionName();
        $this->scope = new VersionScope($connection_name, $capture);

        try {
            $this->enlist($connection_name);

            return $connection->transaction(function () use ($root, $operation, $resolved_options): mixed {
                $result = $this->runInSet($this->openSet($root, $resolved_options, lock: true), $operation);
                $this->enlist($root->connectionName());

                foreach ($this->activeScope()->sets() as $set) {
                    if ($set->changedOnlyTranslations()) {
                        $this->raiseLockVersion($set->root()->model());
                    }

                    $set->finish();
                }

                return $result;
            });
        } finally {
            $this->scope = null;
        }
    }

    /**
     * A run() inside a scope. The same root, on the same instance, joins its set; another root gets a set of its
     * own in the same transaction, its row locked now, with the options given or the outer operation's actor (D43).
     */
    private function runNested(
        VersionSetRoot $root,
        Closure $operation,
        ?VersionSetOptions $options,
    ): mixed {
        $scope = $this->activeScope();
        $existing = $scope->find($root);

        if ($existing instanceof ActiveVersionSet) {
            // Nested scopes share in-process model state, not only a database identity.
            if ($existing->root()->model() !== $root->model()) {
                throw new DistinctVersionSetRootInstanceException;
            }

            if ($options !== null && ! $existing->options()->semanticallyEquals($options)) {
                throw new VersionSetOptionsMismatchException;
            }

            $this->enlist($root->connectionName());

            if ($options !== null) {
                $this->validateRevertedFrom($root, $options);
            }

            return $this->runInSet($existing, $operation);
        }

        $this->enlist($root->connectionName());

        return $this->runInSet(
            $this->openSet($root, $options ?? new VersionSetOptions(actor: $scope->outermost()->options()->actorId()), lock: true),
            $operation,
        );
    }

    private function openSet(VersionSetRoot $root, VersionSetOptions $options, bool $lock): ActiveVersionSet
    {
        if ($lock) {
            $this->lockExistingRoot($root);
        }

        $this->validateRevertedFrom($root, $options);
        $set = new ActiveVersionSet($root, $root->connectionName(), $options, $this->activeScope()->capture());
        $this->activeScope()->add($set);

        return $set;
    }

    private function runInSet(ActiveVersionSet $set, Closure $operation): mixed
    {
        $scope = $this->activeScope();
        $scope->enter($set);

        try {
            return $operation($set);
        } finally {
            $scope->leave();
        }
    }

    /**
     * Raises the optimistic lock version of a root whose revision changed only its translations, with a direct update
     * of that column and no model event, and keeps the instance in step (D23).
     */
    private function raiseLockVersion(Model $root): void
    {
        if (! $root instanceof IOptimisticLockableModel || $root->currentLockVersion() === null) {
            return;
        }

        $column = $root::lockVersionColumn();
        $raised = $root->currentLockVersion() + 1;
        $root->newQueryWithoutScopes()->whereKey($root->getKey())->toBase()->update([$column => $raised]);
        $root->setAttribute($column, $raised);
        $root->syncOriginalAttribute($column);
    }

    private function activeScope(): VersionScope
    {
        return $this->scope ?? throw new LogicException('No version set scope is active.');
    }

    private function lockExistingRoot(VersionSetRoot $root): void
    {
        $model = $root->model();

        if (! $model->exists || $model->getKey() === null) {
            return;
        }

        $dirty_attributes = $model->getDirty();
        $locked = $model->newQueryWithoutScopes()
            ->whereKey($model->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw (new ModelNotFoundException)->setModel($model::class, [$model->getKey()]);
        }

        // The version the instance carries is the one its caller read: a newer one on the locked row means the
        // instance is stale, and refreshing it would let its save overwrite the other write (D34).
        if ($model instanceof IOptimisticLockableModel && $model->currentLockVersion() !== null) {
            $locked_version = $locked->getAttribute($model::lockVersionColumn());

            throw_if(
                is_numeric($locked_version) && (int) $locked_version !== $model->currentLockVersion(),
                StaleModelLockingException::class,
                'Model has been changed since it was loaded.',
            );
        }

        // Realigned to the locked row, keeping what the caller changed and the relations it loaded (D34).
        $model->setRawAttributes($locked->getAttributes(), true);
        $model->setRawAttributes(array_replace($model->getAttributes(), $dirty_attributes));
    }

    private function validateRevertedFrom(VersionSetRoot $root, VersionSetOptions $options): void
    {
        if ($options->kind !== VersionSetKind::Revert) {
            return;
        }

        $target_id = $options->revertedFromId();

        if ($target_id === null || $root->id() === null || $root->id() === '') {
            if ($target_id !== null) {
                throw InvalidRevertedVersionSetException::wrongRoot($target_id);
            }

            return;
        }

        $connection_name = $root->connectionName();
        $input_target = $options->revertedFrom;

        if ($input_target instanceof VersionSet) {
            $target_connection = $input_target->getConnection()->getName();

            if ($target_connection !== $connection_name) {
                throw InvalidRevertedVersionSetException::wrongConnection(
                    $target_id,
                    $connection_name,
                    $target_connection,
                );
            }
        }

        $version_set = new VersionSet;
        $version_set->setConnection($connection_name);
        $target = $version_set->newQuery()->find($target_id);

        if (! $target instanceof VersionSet) {
            throw InvalidRevertedVersionSetException::notFound($target_id, $connection_name);
        }

        if ($target->root_type !== $root->type()
            || $target->root_id !== $root->id()
            || $target->root_connection_ref !== $root->connectionName()
            || $target->root_table_ref !== $root->tableName()) {
            throw InvalidRevertedVersionSetException::wrongRoot($target_id);
        }
    }
}
