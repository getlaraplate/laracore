<?php

declare(strict_types=1);

namespace Modules\Core\Versioning\Exceptions;

use RuntimeException;

/**
 * A revert that may not run, refused before any write, with the reason a client can show.
 */
final class RevertRefusedException extends RuntimeException
{
    public const string NOT_VERSIONED = 'not-versioned';

    public const string NOT_A_REVISION_OF_THIS_RECORD = 'not-a-revision-of-this-record';

    public const string NOT_A_ROOT_ROW = 'not-a-root-row';

    public const string NO_COMPLETE_ANCHOR = 'no-complete-anchor';

    public const string INCOMPLETE_COVERAGE = 'incomplete-coverage';

    public const string NOT_ATOMIC = 'not-atomic';

    public const string EPOCH_CHANGED = 'epoch-changed';

    public const string UNSUPPORTED_RELATION = 'unsupported-relation';

    private const array MESSAGES = [
        self::NOT_VERSIONED => 'Versioning is off for this table: a revert would leave no history.',
        self::NOT_A_REVISION_OF_THIS_RECORD => 'The revision is not one of this record.',
        self::NOT_A_ROOT_ROW => 'The version is a translation or a link: revert to its revision instead.',
        self::NO_COMPLETE_ANCHOR => 'No complete image of the record exists at or before the revision.',
        self::INCOMPLETE_COVERAGE => 'Not every relation of the record supports a complete revert.',
        self::NOT_ATOMIC => 'A change between the last complete image and the revision was not captured atomically.',
        self::EPOCH_CHANGED => 'The schema of the record changed since the revision.',
        self::UNSUPPORTED_RELATION => 'The record owns a relation a revert cannot put back.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? $reason);
    }
}
