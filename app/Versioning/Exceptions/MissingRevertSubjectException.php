<?php

declare(strict_types=1);

namespace Modules\Core\Versioning\Exceptions;

use RuntimeException;

final class MissingRevertSubjectException extends RuntimeException
{
    /**
     * @param  list<int|string>  $missingSubjects
     */
    public function __construct(
        public readonly string $relation,
        public readonly array $missingSubjects,
    ) {
        parent::__construct(sprintf(
            "Cannot revert relation '%s': %d referenced subject(s) no longer exist. Pass force to skip them.",
            $relation,
            count($missingSubjects),
        ));
    }
}
