<?php

declare(strict_types=1);

namespace Modules\Core\Versioning\Exceptions;

use RuntimeException;

/**
 * The record moved past the revision the caller read: a revert would overwrite a change it never saw.
 */
final class RevisionConflictException extends RuntimeException
{
    public function __construct(
        public readonly int $expectedRevision,
        public readonly ?int $currentRevision,
    ) {
        parent::__construct(sprintf(
            'Cannot revert: the record is at revision %s but revision %d was expected.',
            $currentRevision === null ? 'none' : (string) $currentRevision,
            $expectedRevision,
        ));
    }
}
