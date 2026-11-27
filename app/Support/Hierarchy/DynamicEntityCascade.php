<?php

declare(strict_types=1);

namespace Modules\Core\Support\Hierarchy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use LogicException;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Entity;
use Modules\Core\Models\Pivot\Presettable;
use Modules\Core\Models\Preset;
use Modules\Core\Models\Template;

/**
 * The rows a hard delete of a dynamic-entity owner takes along by cascade from a table that carries `entity_id`
 * and `presettable_id` (taxonomies, a module's dynamic contents).
 *
 * The chains, as the migrations declare them: presettables go with their preset (composite foreign key on
 * `entity_id, preset_id`, and the presets delete trigger by `preset_id`), presets with their entity and with their
 * template; such a table's rows go with their entity and with their presettable.
 */
final class DynamicEntityCascade
{
    /**
     * The owners, by class, with their tables.
     *
     * @var array<class-string<Model>, string>
     */
    public const array OWNERS = [
        Entity::class => CoreTables::Entities->value,
        Preset::class => CoreTables::Presets->value,
        Presettable::class => CoreTables::Presettables->value,
        Template::class => CoreTables::Templates->value,
    ];

    private function __construct() {}

    /**
     * Narrow a query on a table carrying `entity_id` and `presettable_id` to the rows the owner's hard delete takes
     * along.
     *
     * @throws LogicException When the owner is none of {@see self::OWNERS}
     */
    public static function rowsTakenBy(Builder $rows, Model $owner): Builder
    {
        $connection = $owner->getConnection();
        $key = $owner->getKey();

        return match (true) {
            $owner instanceof Entity => $rows->where(static function (Builder $taken) use ($connection, $key): void {
                $taken->where('entity_id', $key)->orWhereIn(
                    'presettable_id',
                    $connection->table(CoreTables::Presettables->value)->select('id')->where('entity_id', $key),
                );
            }),
            $owner instanceof Preset => $rows->whereIn(
                'presettable_id',
                $connection->table(CoreTables::Presettables->value)->select('id')->where('preset_id', $key),
            ),
            $owner instanceof Presettable => $rows->where('presettable_id', $key),
            $owner instanceof Template => $rows->whereIn(
                'presettable_id',
                $connection->table(CoreTables::Presettables->value)->select('id')->whereIn(
                    'preset_id',
                    $connection->table(CoreTables::Presets->value)->select('id')->where('template_id', $key),
                ),
            ),
            default => throw new LogicException(sprintf('[%s] is not a dynamic-entity owner.', $owner::class)),
        };
    }
}
