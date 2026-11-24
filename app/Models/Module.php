<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\Core\Contracts\IActivatableModel;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\ModuleDatabaseActivator;
use Modules\Core\Models\Concerns\HasActivation;
use Override;

/**
 * One row per module found on disk, holding whether it is active and where its frontend app lives.
 *
 * Deliberately extends the base Eloquent {@see Model}, not {@see \Modules\Core\Overrides\Model}:
 * {@see ModuleDatabaseActivator} reads this table while providers are still registering, and the
 * base class would pull in versioning and soft deletes, which are themselves driven by settings
 * and modules. {@see SeedRun} follows the same pattern for the same reason.
 *
 * @property int $id
 * @property string $name The name of the module
 * @property bool $is_active Is the module active
 * @property string|null $url The url of the frontend app of the module
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class Module extends Model implements IActivatableModel
{
    use HasActivation;

    public const string CORE = 'Core';

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::Modules->value;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'name',
        'url',
    ];

    #[Override]
    protected static function booted(): void
    {
        self::saving(static function (self $module): void {
            if ($module->name === self::CORE && ! $module->is_active) {
                throw new LogicException('The Core module cannot be disabled.');
            }
        });

        self::deleting(static function (self $module): void {
            if ($module->name === self::CORE) {
                throw new LogicException('The Core module cannot be deleted.');
            }
        });

        // The panel writes through Eloquent, the activator reads through a cached query.
        self::saved(static fn () => ModuleDatabaseActivator::flushStatusesCache());
        self::deleted(static fn () => ModuleDatabaseActivator::flushStatusesCache());
    }
}
