<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Hierarchy;

use Modules\Core\Models\Entity;
use Modules\Core\Tests\Stubs\DynamicEntities\StubEntityType;
use Override;

/**
 * A module's own entity, as Shop's or ERP's: a subclass of Core's abstract {@see Entity} outside Core's models,
 * which inherits Core's observers from the abstract class.
 */
final class ModuleEntityStub extends Entity
{
    #[Override]
    protected static function getEntityTypeEnumClass(): string
    {
        return StubEntityType::class;
    }
}
