<?php

declare(strict_types=1);

namespace Modules\Core\Observers;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Support\Hierarchy\OwnerCascadeRegistry;

/**
 * Keeps the closure tables in step when an owner's hard delete removes hierarchy rows by a cascading foreign key,
 * through the cascades registered in {@see OwnerCascadeRegistry}. Attached with `#[ObservedBy]` to Core's owners,
 * abstract ones included, so every module's subclass inherits it; a module attaches it to its own owners.
 */
final readonly class HierarchyOwnerObserver
{
    public function __construct(private OwnerCascadeRegistry $cascades) {}

    public function deleting(Model $owner): void
    {
        $this->cascades->ownerDeleting($owner);
    }

    public function deleted(Model $owner): void
    {
        $this->cascades->ownerDeleted($owner);
    }
}
