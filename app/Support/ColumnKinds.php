<?php

declare(strict_types=1);

namespace Modules\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Modules\Core\Enums\ColumnKind;
use Modules\Core\Inspector\Entities\Column;
use Modules\Core\Inspector\Entities\ForeignKey;
use Modules\Core\Inspector\SchemaInspector;

/**
 * Whether a column of a model is numeric, temporal or neither, and whether it is a key: what the aggregate
 * checks before it builds a measure, a period or a bucket on the column.
 *
 * The cast of the model decides first, except that a numeric schema type overrides a temporal cast. A column whose
 * cast says nothing about its kind is read by its schema type: the first attribute the inspector keeps for a column,
 * its `type_name`. `Column::$type` cannot serve, because it is the Doctrine name of the raw type and a `numeric` or
 * `double precision` column has none. The name is lower-cased and cut of its precision and `unsigned` before it is
 * mapped, so `decimal(15,4)` is `decimal`.
 *
 * `tinyint` is never numeric: on MySQL it is how a boolean is stored, and the precision that tells the two
 * apart is cut. `int4`, `float8` and the like are what PostgreSQL gives as `type_name`; the SQL name it gives
 * as `type` (`integer`, `double precision`) is not kept by the inspector. `number` (`NUMBER(p,s)` once cut),
 * `binary_double` and `binary_float` are the names of Oracle, `smallmoney`, `datetime2`, `smalldatetime` and
 * `datetimeoffset` those of SQL Server. A `time` column holds no date, so it is neither.
 */
final class ColumnKinds
{
    private const array NUMERIC_CASTS = ['int', 'integer', 'float', 'double', 'real', 'decimal'];

    private const array TEMPORAL_CASTS = ['date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp'];

    private const array NUMERIC_TYPES = [
        'int', 'integer', 'bigint', 'smallint', 'mediumint', 'decimal', 'numeric', 'float', 'double', 'real',
        'double precision', 'money', 'smallmoney', 'int2', 'int4', 'int8', 'float4', 'float8', 'number',
        'binary_double', 'binary_float',
    ];

    private const array TEMPORAL_TYPES = [
        'date', 'datetime', 'datetime2', 'smalldatetime', 'datetimeoffset', 'timestamp', 'timestamptz', 'datetimetz',
        'timestamp with time zone', 'timestamp without time zone',
    ];

    /**
     * Whether the name is a column of the table of the model, which an accessor or a relation is not.
     */
    public static function exists(Model $model, string $column): bool
    {
        return SchemaInspector::getInstance()->hasColumn($column, $model->getTable(), $model->getConnectionName());
    }

    /**
     * A key is still numeric here: {@see self::isKey()} is what keeps it from being summed.
     *
     * A temporal cast on a column the schema stores as a number (unix seconds cast as `timestamp`) is neither
     * temporal nor numeric: the database reads its values as numbers, so the day of a value and the date bounds of
     * a period would mean nothing, and its figures are not amounts.
     */
    public static function of(Model $model, string $column): ColumnKind
    {
        $cast = $model->getCasts()[$column] ?? null;
        $cast_kind = is_string($cast) ? self::kindOfCast($cast) : ColumnKind::Other;

        if ($cast_kind === ColumnKind::Numeric) {
            return $cast_kind;
        }

        $inspected = SchemaInspector::getInstance()->column($column, $model->getTable(), $model->getConnectionName());
        $schema_kind = $inspected instanceof Column ? self::kindOfSchemaType($inspected) : ColumnKind::Other;

        if ($cast_kind === ColumnKind::Temporal) {
            return $schema_kind === ColumnKind::Numeric ? ColumnKind::Other : $cast_kind;
        }

        return $schema_kind;
    }

    /**
     * Whether the column is, or is part of, the primary key of the model, or is the local side of a foreign
     * key of its table.
     */
    public static function isKey(Model $model, string $column): bool
    {
        if (in_array($column, Arr::wrap($model->getKeyName()), true)) {
            return true;
        }

        return SchemaInspector::getInstance()
            ->foreignKeys($model->getTable(), $model->getConnectionName())
            ->contains(static fn (ForeignKey $foreign): bool => $foreign->localColumnNames()->contains($column));
    }

    private static function kindOfCast(string $cast): ColumnKind
    {
        $name = Str::lower(Str::before(mb_trim($cast), ':'));

        return match (true) {
            in_array($name, self::NUMERIC_CASTS, true) => ColumnKind::Numeric,
            in_array($name, self::TEMPORAL_CASTS, true) => ColumnKind::Temporal,
            default => ColumnKind::Other,
        };
    }

    /**
     * The inspector lists the type name first among the attributes of a column.
     */
    private static function kindOfSchemaType(Column $column): ColumnKind
    {
        $type = $column->attributes->first();

        if (! is_string($type)) {
            return ColumnKind::Other;
        }

        $name = self::normalizeSchemaType($type);

        return match (true) {
            in_array($name, self::NUMERIC_TYPES, true) => ColumnKind::Numeric,
            in_array($name, self::TEMPORAL_TYPES, true) => ColumnKind::Temporal,
            default => ColumnKind::Other,
        };
    }

    private static function normalizeSchemaType(string $type): string
    {
        $name = preg_replace(['/\([^)]*\)/', '/\bunsigned\b/', '/\s+/'], ['', '', ' '], Str::lower($type)) ?? $type;

        return mb_trim($name);
    }
}
