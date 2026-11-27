<?php

declare(strict_types=1);

namespace Modules\Core\Enums;

/**
 * What a revert to a revision puts back (D56, spec "Revert modes").
 */
enum RevertMode: string
{
    /**
     * The root's own columns.
     */
    case Scalar = 'scalar';

    /**
     * The membership of the registered `reference` relations, never the related records.
     */
    case Link = 'link';

    /**
     * The owned parts: the translations, by locale.
     */
    case OwnedPart = 'owned_part';

    /**
     * Columns, translations and links, offered only when coverage, capture and epoch allow it.
     */
    case Complete = 'complete';

    public function includesColumns(): bool
    {
        return $this === self::Scalar || $this === self::Complete;
    }

    public function includesLinks(): bool
    {
        return $this === self::Link || $this === self::Complete;
    }

    public function includesOwnedParts(): bool
    {
        return $this === self::OwnedPart || $this === self::Complete;
    }
}
