<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Hierarchy;

use Closure;
use Generator;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Core\Enums\CoreTables;
use UnitEnum;

/**
 * A connection that reads the hierarchy lock row as an older snapshot would: what a MySQL transaction under
 * REPEATABLE READ sees with plain reads after another writer committed, or a row another writer inserted that the
 * snapshot cannot see (null). Locking reads, which a query builder sends to the write connection, read the database
 * as the current row; they can be faked too. Writes, and every other read, go to the wrapped connection. It records
 * the kind of each read of the lock row, in order.
 */
final class StaleSnapshotConnection implements ConnectionInterface
{
    /**
     * The versions the next reads of the lock row return, in order; null for no row.
     *
     * @var list<int|null>
     */
    private array $staleVersions = [];

    private bool $repeatLast = false;

    private bool $fakeLockingReads = false;

    /**
     * The reads of the lock row so far, in order: `locking` or `snapshot`.
     *
     * @var list<string>
     */
    private array $lockReads = [];

    public function __construct(private readonly Connection $inner) {}

    /**
     * Make the next plain reads of the lock row (or its locking reads, with `$lockingReads`) return these versions
     * in turn (null: no row), then read the database; with `$repeatLast`, the last one stays for every later read.
     *
     * @param  list<int|null>  $versions
     */
    public function readLockVersionsAs(array $versions, bool $repeatLast = false, bool $lockingReads = false): void
    {
        $this->staleVersions = $versions;
        $this->repeatLast = $repeatLast;
        $this->fakeLockingReads = $lockingReads;
    }

    /**
     * The reads of the lock row so far, in order: `locking` for a locking read, `snapshot` for a plain one.
     *
     * @return list<string>
     */
    public function lockReads(): array
    {
        return $this->lockReads;
    }

    public function table($table, $as = null): Builder
    {
        /** @var Closure|Builder|UnitEnum|string $table */
        return new Builder($this, $this->inner->getQueryGrammar(), $this->inner->getPostProcessor())->from($table, $as);
    }

    public function raw($value): Expression
    {
        return $this->inner->raw($value);
    }

    public function selectOne($query, $bindings = [], $useReadPdo = true): mixed
    {
        return $this->inner->selectOne($query, $bindings, $useReadPdo);
    }

    public function scalar($query, $bindings = [], $useReadPdo = true): mixed
    {
        return $this->inner->scalar($query, $bindings, $useReadPdo);
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     * @param  array<array-key, mixed>  $fetchUsing
     * @return array<array-key, mixed>
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        $is_lock_read = str_starts_with($query, 'select') && str_contains($query, CoreTables::HierarchyLocks->value);

        if ($is_lock_read) {
            $this->lockReads[] = $useReadPdo ? 'snapshot' : 'locking';
        }

        if ($is_lock_read && $this->staleVersions !== [] && $useReadPdo !== $this->fakeLockingReads) {
            $version = $this->repeatLast && count($this->staleVersions) === 1 ? $this->staleVersions[0] : array_shift($this->staleVersions);

            return $version === null ? [] : [(object) ['version' => $version]];
        }

        return $this->inner->select($query, $bindings, $useReadPdo, $fetchUsing);
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     * @param  array<array-key, mixed>  $fetchUsing
     */
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): Generator
    {
        return $this->inner->cursor($query, $bindings, $useReadPdo, $fetchUsing);
    }

    public function insert($query, $bindings = []): bool
    {
        return $this->inner->insert($query, $bindings);
    }

    public function update($query, $bindings = []): int
    {
        return $this->inner->update($query, $bindings);
    }

    public function delete($query, $bindings = []): int
    {
        return $this->inner->delete($query, $bindings);
    }

    public function statement($query, $bindings = []): bool
    {
        return $this->inner->statement($query, $bindings);
    }

    public function affectingStatement($query, $bindings = []): int
    {
        return $this->inner->affectingStatement($query, $bindings);
    }

    public function unprepared($query): bool
    {
        return $this->inner->unprepared($query);
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     * @return array<array-key, mixed>
     */
    public function prepareBindings(array $bindings): array
    {
        return $this->inner->prepareBindings($bindings);
    }

    public function transaction(Closure $callback, $attempts = 1): mixed
    {
        return $this->inner->transaction(fn (): mixed => $callback($this), $attempts);
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollBack($toLevel = null): void
    {
        $this->inner->rollBack($toLevel);
    }

    public function transactionLevel(): int
    {
        return $this->inner->transactionLevel();
    }

    /**
     * @return array<array-key, mixed>
     */
    public function pretend(Closure $callback): array
    {
        return $this->inner->pretend($callback);
    }

    public function getDatabaseName(): string
    {
        return $this->inner->getDatabaseName();
    }
}
