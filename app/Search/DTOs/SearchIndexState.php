<?php

declare(strict_types=1);

namespace Modules\Core\Search\DTOs;

/**
 * The state of one model's search index compared with what the model declares, as read by
 * {@see \Modules\Core\Search\Services\SearchIndexInspector}.
 */
final readonly class SearchIndexState
{
    /**
     * @param  bool  $managed  whether the engine keeps an index of its own (the database engine does not)
     * @param  bool  $exists  whether the index exists; always true for an engine that cannot tell
     * @param  list<string>  $mismatches  declared-vs-live mapping differences, as human-readable lines
     */
    public function __construct(
        public bool $managed,
        public bool $exists,
        public array $mismatches = [],
    ) {}

    public static function unmanaged(): self
    {
        return new self(managed: false, exists: true);
    }

    public function isInSync(): bool
    {
        return ! $this->managed || ($this->exists && $this->mismatches === []);
    }
}
