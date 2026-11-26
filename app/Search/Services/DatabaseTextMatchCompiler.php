<?php

declare(strict_types=1);

namespace Modules\Core\Search\Services;

use Modules\Core\Search\DTOs\TextMatchOptions;
use Modules\Core\Support\Db2Drivers;

final readonly class DatabaseTextMatchCompiler
{
    /**
     * @return array{sql: string, bindings: list<float|string>, degraded: bool}
     */
    public function compile(string $driver, string $column, string $query, TextMatchOptions $options): array
    {
        $portable = $this->like($driver, $column, $options->prefix ? $query . '%' : '%' . $query . '%');

        if ($driver !== 'pgsql' || ! $options->typoTolerance || $options->maxEdits === 0 || $options->minimumTermLength > mb_strlen($query)) {
            return [
                'sql' => $portable['sql'],
                'bindings' => $portable['bindings'],
                'degraded' => $options->typoTolerance && $options->maxEdits > 0,
            ];
        }

        return [
            'sql' => sprintf('(%s OR strict_word_similarity(LOWER(?), LOWER(CAST(%s AS TEXT))) >= ?)', $portable['sql'], $column),
            'bindings' => [...$portable['bindings'], $query, $options->similarityThreshold],
            'degraded' => false,
        ];
    }

    /**
     * A case-insensitive LIKE of the wrapped column against the pattern.
     *
     * Db2 refuses an untyped parameter marker as the argument of a function (SQL0418), so on Db2 the pattern is
     * lower-cased in PHP and bound bare. Every other driver keeps `LOWER(?)`: the database folds the pattern as it
     * folds the column (ASCII only on SQLite and on a PostgreSQL with the C locale), so the matches do not change.
     *
     * @return array{sql: string, bindings: list<string>}
     */
    public function like(string $driver, string $column, string $pattern): array
    {
        if (Db2Drivers::is($driver)) {
            return ['sql' => sprintf('LOWER(%s) LIKE ?', $column), 'bindings' => [mb_strtolower($pattern)]];
        }

        return ['sql' => sprintf('LOWER(%s) LIKE LOWER(?)', $column), 'bindings' => [$pattern]];
    }
}
