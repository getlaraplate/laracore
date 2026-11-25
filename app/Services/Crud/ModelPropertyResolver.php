<?php

declare(strict_types=1);

namespace Modules\Core\Services\Crud;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\IMountsRelatedAttributes;
use Modules\Core\Contracts\ITranslatableModel;
use Modules\Core\Inspector\SchemaInspector;
use Modules\Core\Models\Concerns\HasDynamicContents;

/**
 * Tells where a flat filter property lives when it is not a column of the model's own table.
 *
 * A client filters on `title` or `color` without knowing how the entity stores it: the property is
 * a column of the table, a field of the translation table, a dynamic field kept in a JSON
 * container, or an attribute mounted from a related record. The order is that of the models
 * themselves (column, translatable, dynamic, mounted).
 */
final class ModelPropertyResolver
{
    public const string TRANSLATED = 'translated';

    public const string DYNAMIC = 'dynamic';

    public const string MOUNTED = 'mounted';

    /**
     * JSON containers that can hold dynamic fields on the main table: the shared one of translatable
     * models and the only one of the models that are not translatable.
     *
     * @var list<string>
     */
    private const array MAIN_CONTAINERS = ['shared_components', 'components'];

    private const string TRANSLATION_CONTAINER = 'components';

    /**
     * The kind of a property that is not a column: {@see self::TRANSLATED}, {@see self::DYNAMIC},
     * or null when the property is a column or the model has nothing the property could mean.
     *
     * Dynamic fields are not checked against the presets: a recordset mixes entities with different
     * presets, so the field is looked for in every record and simply absent from the others.
     *
     * @return self::TRANSLATED|self::DYNAMIC|self::MOUNTED|null
     */
    public static function resolve(Model $model, string $property): ?string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $property) !== 1) {
            return null;
        }

        $traits = class_uses_recursive($model);
        $is_translatable = $model instanceof ITranslatableModel;
        $has_dynamic_contents = isset($traits[HasDynamicContents::class]);

        $mounts_attributes = $model instanceof IMountsRelatedAttributes;

        if (! $is_translatable && ! $has_dynamic_contents && ! $mounts_attributes) {
            return null;
        }

        $inspector = SchemaInspector::getInstance();

        if (
            ! $inspector->hasTable($model->getTable(), $model->getConnectionName())
            || $inspector->hasColumn($property, $model->getTable(), $model->getConnectionName())
        ) {
            return null;
        }

        if ($model instanceof ITranslatableModel && in_array($property, $model::getTranslatableFields(), true)) {
            return self::TRANSLATED;
        }

        if ($has_dynamic_contents) {
            return self::DYNAMIC;
        }

        return $model instanceof IMountsRelatedAttributes && $model->isMountedAttribute($property) ? self::MOUNTED : null;
    }

    /**
     * The JSON columns of the main table that can hold dynamic fields.
     *
     * @return list<string>
     */
    public static function mainContainers(Model $model): array
    {
        $inspector = SchemaInspector::getInstance();

        return array_values(array_filter(
            self::MAIN_CONTAINERS,
            static fn (string $column): bool => $inspector->hasColumn($column, $model->getTable(), $model->getConnectionName()),
        ));
    }

    /**
     * The JSON column of the translation table that holds the translated dynamic fields, if the
     * model has translations and that table has it.
     */
    public static function translationContainer(Model $model): ?string
    {
        if (! $model instanceof ITranslatableModel) {
            return null;
        }

        $translation = $model->translations()->getRelated();

        return SchemaInspector::getInstance()->hasColumn(self::TRANSLATION_CONTAINER, $translation->getTable(), $translation->getConnectionName())
            ? self::TRANSLATION_CONTAINER
            : null;
    }
}
