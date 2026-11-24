<?php

declare(strict_types=1);

namespace Modules\Core\Support;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Throwable;

/**
 * The concrete models of the application ({@see models()}) that read and write a table, for code that only knows the
 * table, such as a per-table setting. Several models can share one table (an App model over a Core one): every one
 * is returned. The map is built once per list of models and process.
 */
final class TableModels
{
    /**
     * @var array{fingerprint: string, map: array<string, list<class-string<Model>>>}|null
     */
    private static ?array $cache = null;

    /**
     * @return list<Model> A fresh instance of every model of the table, none when no model reads it.
     */
    public static function for(string $table): array
    {
        $models = [];

        foreach (self::map()[$table] ?? [] as $class) {
            $models[] = new $class();
        }

        return $models;
    }

    /**
     * @return array<string, list<class-string<Model>>>
     */
    private static function map(): array
    {
        $classes = models();
        $fingerprint = md5(implode('|', $classes));

        if (self::$cache !== null && self::$cache['fingerprint'] === $fingerprint) {
            return self::$cache['map'];
        }

        $map = [];

        foreach ($classes as $class) {
            $table = self::tableOf($class);

            if ($table !== null) {
                $map[$table][] = $class;
            }
        }

        self::$cache = ['fingerprint' => $fingerprint, 'map' => $map];

        return $map;
    }

    /**
     * The table of a concrete model, built through its constructor (a model may set its table there); null for an
     * abstract class, a non-model or a model that cannot be built without arguments.
     *
     * @param  class-string  $class
     */
    private static function tableOf(string $class): ?string
    {
        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || new ReflectionClass($class)->isAbstract()) {
            return null;
        }

        try {
            return new $class()->getTable();
        } catch (Throwable) {
            return null;
        }
    }
}
