<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Console;

use Modules\Core\Authorization\Contracts\DeclaresPermissions;

/**
 * Stands in for the modules' permission declarations in a real `PermissionManifest`, so the refresh tests do not
 * depend on which modules are installed, nor on mocking a final class.
 */
final class DeclaredPermissionsStub implements DeclaresPermissions
{
    /**
     * @var array<class-string<\Illuminate\Database\Eloquent\Model>, list<string>>
     */
    public static array $declared = [];

    public static function operations(): array
    {
        return self::$declared;
    }

    public static function excludedModels(): array
    {
        return [];
    }
}
