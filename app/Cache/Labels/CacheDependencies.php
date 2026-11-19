<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

/**
 * What a cached entry depends on.
 */
final readonly class CacheDependencies
{
    /**
     * @param  list<string>  $labels  Labels whose flush invalidates the entry.
     * @param  list<string>  $row_tables  Tables whose row labels the entry may also carry.
     * @param  bool  $complete  False when the dependencies could not be fully determined. An entry with incomplete
     *                          dependencies is never stored, on any index; a displayed `morphTo`, whose rows are
     *                          known only after the query, is incomplete until it gets a flag of its own.
     */
    public function __construct(
        public array $labels,
        public array $row_tables = [],
        public bool $complete = true,
    ) {}

    public function withLabels(string ...$labels): self
    {
        return new self([...$this->labels, ...$labels], $this->row_tables, $this->complete);
    }
}
