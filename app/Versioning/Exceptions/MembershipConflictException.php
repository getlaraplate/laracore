<?php

declare(strict_types=1);

namespace Modules\Core\Versioning\Exceptions;

use RuntimeException;

/**
 * The related records of a relation changed since the client loaded them: replacing them would drop a change it never
 * saw (D40).
 */
final class MembershipConflictException extends RuntimeException
{
    /**
     * @param  list<int|string>  $expected
     * @param  list<int|string>  $current
     */
    public function __construct(
        public readonly string $relation,
        public readonly array $expected,
        public readonly array $current,
    ) {
        parent::__construct(sprintf("The records of '%s' changed since they were loaded: reload them before saving.", $relation));
    }
}
