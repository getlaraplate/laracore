<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The application's database-notification model. It is the framework's
 * {@see DatabaseNotification} (same `notifications` table, so Laravel's Notifiable
 * and Filament's native bell keep working) plus one derived column: `module_name`
 * mirrors the `data->scope` key so a module-scoped tray can filter at the database
 * level. The mirror is maintained here — on save — rather than in every producer,
 * and stays portable across PostgreSQL and SQLite (no DB-specific generated column).
 *
 * @property string $id
 * @property string $type
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property string|null $module_name
 * @property \Carbon\CarbonImmutable|null $read_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property array<array-key, mixed> $data
 */
final class Notification extends DatabaseNotification
{
    /**
     * Narrow to one module's notifications by the derived `module_name` column; a
     * null/empty module is a no-op (the whole tray).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForModule(Builder $query, ?string $module): Builder
    {
        return $module === null || $module === '' ? $query : $query->where('module_name', $module);
    }

    protected static function booted(): void
    {
        self::saving(static function (self $notification): void {
            $data = $notification->data;
            $scope = is_array($data) ? ($data['scope'] ?? null) : null;
            $notification->module_name = is_string($scope) && $scope !== '' ? $scope : null;
        });
    }
}
