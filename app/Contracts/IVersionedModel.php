<?php

declare(strict_types=1);

namespace Modules\Core\Contracts;

use Modules\Core\Enums\VersionStrategy;

/**
 * A model that keeps a history of its rows.
 *
 * Supplied by {@see \Modules\Core\Models\Concerns\HasVersions}. The code that records a change on somebody else's
 * history (a translation or a part on its owner, a pivot on its root) asks the other model for this contract before it
 * reads its versioning switch and its strategy.
 */
interface IVersionedModel
{
    /**
     * Whether versioning is switched on for this class.
     */
    public static function getVersioning(): bool;

    /**
     * The strategy a version of this model is written with, or false when the model is never versioned.
     */
    public function getVersionStrategy(): VersionStrategy|false;

    /**
     * The user a version is attributed to, or null when the record names none.
     */
    public function getVersionUserId(): ?int;
}
