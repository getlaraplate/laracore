<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Hierarchy;

use Modules\Core\Models\Entity;
use Modules\Core\Tests\Stubs\DynamicEntities\StubEntityType;
use Override;

/**
 * A module entity with soft deletes switched off in code: `delete()` removes the row and fires `deleting` alone,
 * no `forceDeleting`.
 */
final class HardDeletingModuleEntityStub extends Entity
{
    protected bool $softDeletesEnabled = false;

    #[Override]
    protected static function getEntityTypeEnumClass(): string
    {
        return StubEntityType::class;
    }
}
