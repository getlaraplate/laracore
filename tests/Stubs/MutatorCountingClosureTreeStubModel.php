<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs;

use Override;

/**
 * A closure tree node standing for models whose `setAttribute()` does work (a dynamic-field lookup that lazy loads
 * relations): it counts the attributes written through the mutator path.
 */
final class MutatorCountingClosureTreeStubModel extends ClosureTreeStubModel
{
    /**
     * @var list<string>
     */
    public static array $mutatedKeys = [];

    #[Override]
    public function setAttribute($key, $value)
    {
        self::$mutatedKeys[] = (string) $key;

        return parent::setAttribute($key, $value);
    }
}
