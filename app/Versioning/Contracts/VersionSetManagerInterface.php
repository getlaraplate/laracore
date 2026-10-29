<?php

declare(strict_types=1);

namespace Modules\Core\Versioning\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Versioning\ActiveVersionSet;
use Modules\Core\Versioning\Data\VersionSetOptions;
use Modules\Core\Versioning\Data\VersionSetRoot;

interface VersionSetManagerInterface
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(
        VersionSetRoot $root,
        Closure $operation,
        ?VersionSetOptions $options = null,
    ): mixed;

    /**
     * Opens a scope for a change whose business statement already ran: its sets are atomic only when the application
     * had a transaction open on the root's connection (D47, D52).
     */
    public function runAfterTheFact(VersionSetRoot $root, Closure $operation, VersionSetOptions $options): mixed;

    /**
     * The set of a root inside the active scope, opened if the scope has none for it yet (D43).
     */
    public function setFor(Model $root): ActiveVersionSet;

    public function enlist(string $connection): void;

    /**
     * Whether the root is being deleted by the hard-delete coordinator inside the active scope (D54).
     */
    public function isBeingHardDeleted(Model $root): bool;

    public function current(): ?ActiveVersionSet;
}
