<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use RuntimeException;

/**
 * The store of a label index answered a call with an error instead of a result (phpredis returns false for an error
 * reply, such as `-OOM`, where Predis throws). {@see FailoverLabelIndex} counts it as an outage: a flush that did not
 * happen degrades the index and is made good by the recovery purge, instead of being lost without a trace.
 */
final class LabelIndexUnavailableException extends RuntimeException
{
    public static function errorReply(string $call, mixed $reply, string $error): self
    {
        return new self(sprintf(
            'The label index store answered the %s script with %s instead of a result%s.',
            $call,
            get_debug_type($reply),
            $error === '' ? '' : ': ' . $error,
        ));
    }
}
