<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs;

use Override;

/**
 * A tree node whose own `deleting` listener, registered after the hierarchy's, moves the children of the node being
 * deleted up to its parent with a save each, the "children move up to the grandparent" pattern.
 */
final class ReparentingDeleteClosureTreeStubModel extends ClosureTreeStubModel
{
    #[Override]
    protected static function booted(): void
    {
        self::deleting(static function (self $node): void {
            foreach ($node->children()->get() as $child) {
                $child->setAttribute('parent_id', $node->getAttribute('parent_id'));
                $child->save();
            }
        });
    }
}
