<?php

declare(strict_types=1);

namespace Modules\Core\Search\Extensions;

use Illuminate\Database\Connection;
use Illuminate\Validation\ValidationException;
use Modules\Core\Contracts\IExtendsModel;
use Modules\Core\Enums\CoreTables;

/**
 * Refuses a dynamic field named like an own column of an extender on a preset of that extender's entity.
 *
 * An extender's merge routes its own columns to itself and every other key to its host row, so a dynamic component
 * of the host with the name of an own column would be stored on the host and never read back through the extender:
 * the field would be shadowed, silently, whatever its type. Dynamic fields are configured at runtime, so the rule is
 * checked when a name enters a preset: when a field is attached to it ({@see \Modules\Core\Observers\FieldableObserver})
 * and when a field already attached is renamed ({@see \Modules\Core\Observers\FieldObserver}).
 * Spec `docs/superpowers/specs/2026-11-17-extended-entity-search-design.md`, XS17 (decided 2026-11-17; a matching
 * type does not make the shared name acceptable, unlike `extension` fields under XS16).
 */
final readonly class ExtenderColumnGuard
{
    public function __construct(private ExtensionRegistry $registry) {}

    /**
     * @param  string  $attribute  The input the validation error is reported on
     *
     * @throws ValidationException when the name is an own column of an extender of the preset's entity
     */
    public function assertNotShadowed(string $field_name, int|string $preset_id, Connection $connection, string $attribute): void
    {
        $entity_type = $connection->table(CoreTables::Presets->value)
            ->join(CoreTables::Entities->value, CoreTables::Entities->value . '.id', '=', CoreTables::Presets->value . '.entity_id')
            ->where(CoreTables::Presets->value . '.id', $preset_id)
            ->value(CoreTables::Entities->value . '.type');

        if (! is_string($entity_type) || $entity_type === '') {
            return;
        }

        foreach ($this->registry->all() as $extenders) {
            foreach ($extenders as $alias => $class) {
                /** @var IExtendsModel $extender */
                $extender = new $class();

                if ($extender->extensionEntityType() !== $entity_type || ! in_array($field_name, $extender->extensionOwnColumns(), true)) {
                    continue;
                }

                throw ValidationException::withMessages([$attribute => [sprintf(
                    'The field "%s" cannot be on a preset of the entity "%s": it is a column of the extender %s, which would'
                    . ' hide the dynamic field behind it (XS17, docs/superpowers/specs/2026-11-17-extended-entity-search-design.md).'
                    . ' Choose another name for the field.',
                    $field_name,
                    $entity_type,
                    $alias,
                )]]);
            }
        }
    }
}
