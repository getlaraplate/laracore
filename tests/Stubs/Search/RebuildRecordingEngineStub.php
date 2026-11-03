<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Throwable;

/**
 * Engine double for `scout:rebuild`: answers the index state the test sets and records every
 * call that changes the index (reads are not recorded), so a test can assert what ran and in
 * which order.
 */
final class RebuildRecordingEngineStub
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    /**
     * @param  list<string>  $mismatches
     * @param  bool  $sync_fixes  whether a successful syncMapping() clears the mismatches
     * @param  bool  $rebuild_fixes  whether createIndex() leaves the index in sync
     */
    public function __construct(
        public bool $index_exists = true,
        public array $mismatches = [],
        public bool $sync_result = true,
        public bool $sync_fixes = true,
        public bool $rebuild_fixes = true,
        public ?Throwable $reindex_failure = null,
    ) {}

    public function checkIndex(mixed $model): bool
    {
        return $this->index_exists;
    }

    /**
     * @return list<string>
     */
    public function structureMismatches(mixed $model): array
    {
        return $this->mismatches;
    }

    public function syncMapping(mixed $model): bool
    {
        $this->calls[] = 'syncMapping';

        if ($this->sync_result && $this->sync_fixes) {
            $this->mismatches = [];
        }

        return $this->sync_result;
    }

    public function reindex(): void
    {
        $this->calls[] = 'reindex';

        if ($this->reindex_failure instanceof Throwable) {
            throw $this->reindex_failure;
        }
    }

    public function deleteIndex(string $name): void
    {
        $this->calls[] = 'deleteIndex:' . $name;
        $this->index_exists = false;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function createIndex(mixed $name, array $options = []): void
    {
        $this->calls[] = 'createIndex:' . $name;
        $this->index_exists = true;

        if ($this->rebuild_fixes) {
            $this->mismatches = [];
        }
    }
}
