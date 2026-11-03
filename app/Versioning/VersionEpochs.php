<?php

declare(strict_types=1);

namespace Modules\Core\Versioning;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Core\Models\VersionEpoch;

/**
 * The current schema epoch of each root class, read at its first write in the process and kept for the rest of it
 * (D33): a migration flushes it, and a deploy restarts the workers that hold it.
 */
final class VersionEpochs
{
    /**
     * @var array<string, VersionEpoch>
     */
    private array $current = [];

    /**
     * @var array<string, bool>
     */
    private array $several = [];

    /**
     * The epochs this process inserted inside a transaction still open, by cache key.
     *
     * @var array<string, mixed>
     */
    private array $pending = [];

    public function __construct(private readonly EpochFingerprint $fingerprint) {}

    public function currentFor(Model $root): VersionEpoch
    {
        $key = $root::class . '|' . $root->getConnectionName() . '|' . $root->getTable();

        if (isset($this->current[$key])) {
            return $this->current[$key];
        }

        $definition = $this->fingerprint->definitionOf($root);
        $fingerprint = $this->fingerprint->fingerprintOf($definition);
        $query = $this->epochsOf($root);
        // One read answers both questions, so that hasSeveral() costs no query at the writes that follow.
        $known = $query->clone()->get();
        $current = $known->firstWhere('fingerprint', $fingerprint)
            ?? $this->create($query, $root, $fingerprint, $definition);
        $this->several[$this->shapeKey($root)] = $known->count() + ($known->contains($current) ? 0 : 1) > 1;
        $connection = $root->getConnection();

        // An epoch this process inserted is kept only once its transaction commits: a rollback removes the row, and
        // a cached id of it would fail every later write of the class on the foreign key.
        if ($connection->transactionLevel() > 0 && ($current->wasRecentlyCreated || ($this->pending[$key] ?? null) === $current->getKey())) {
            $this->pending[$key] = $current->getKey();
            $connection->afterCommit(function () use ($key, $current): void {
                unset($this->pending[$key]);
                $this->current[$key] = $current;
            });

            return $current;
        }

        unset($this->pending[$key]);

        return $this->current[$key] = $current;
    }

    /**
     * Whether the root's class has known more than one shape on its table: only then can a row of a root be of
     * another epoch.
     */
    public function hasSeveral(Model $root): bool
    {
        return $this->several[$this->shapeKey($root)] ??= $this->epochsOf($root)->limit(2)->count() > 1;
    }

    public function flush(): void
    {
        $this->current = [];
        $this->several = [];
        $this->pending = [];
    }

    /**
     * Inserts a new shape. Another process may insert it first: its row is then read with a locking read, since the
     * snapshot of a repeatable-read transaction (InnoDB) does not see a row committed after it began.
     *
     * @param  Builder<VersionEpoch>  $query
     * @param  array<string, mixed>  $definition
     */
    private function create(Builder $query, Model $root, string $fingerprint, array $definition): VersionEpoch
    {
        $identity = ['root_type' => $root->getMorphClass(), 'root_table' => $root->getTable(), 'fingerprint' => $fingerprint];

        try {
            return $query->clone()->createOrFirst($identity, ['definition' => $definition, 'first_seen_at' => now()]);
        } catch (UniqueConstraintViolationException $exception) {
            return $query->clone()->where($identity)->sharedLock()->first() ?? throw $exception;
        }
    }

    /**
     * The epochs of the root's class on its table, on the root's connection.
     *
     * @return Builder<VersionEpoch>
     */
    private function epochsOf(Model $root): Builder
    {
        return new VersionEpoch()->setConnection($root->getConnectionName())->newQuery()
            ->where('root_type', $root->getMorphClass())
            ->where('root_table', $root->getTable());
    }

    private function shapeKey(Model $root): string
    {
        return $root->getMorphClass() . '|' . $root->getConnectionName() . '|' . $root->getTable();
    }
}
