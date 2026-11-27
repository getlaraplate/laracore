<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IDeclaresCacheDependencies;
use Modules\Core\Models\Role;
use Override;

/**
 * The related side of {@see CacheDependencyStubModel}: it belongs to a role and declares a table its reads depend on.
 */
final class CacheDependencyStubChild extends Model implements IDeclaresCacheDependencies
{
    public const string TABLE = 'cache_dependency_stub_children';

    public const string DECLARED_TABLE = 'cache_dependency_stub_audits';

    protected $table = self::TABLE;

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    #[Override]
    public function cacheDependencyTables(): array
    {
        return [self::DECLARED_TABLE];
    }
}
