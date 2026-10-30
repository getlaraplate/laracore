<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Support;

use Modules\Core\Search\Enums\QueryClass;

/**
 * Checks that a committed retrieval tuning profile is backed by a measurement.
 *
 * A profile that only restates the L0 constants changes nothing and needs no source. One with
 * measured values must name, in `report`, a report of `test:ai:tune-retrieval` kept under
 * {@see self::REPORT_DIRECTORY} (relative to the Core module), and that report must have passed the
 * held-out validation and the noise check and hold the same winner and class overrides as the
 * profile. Otherwise a hand-picked number cannot be told from a measured one.
 */
final class RetrievalTuningProfileAudit
{
    public const string REPORT_DIRECTORY = 'docs/evaluations/retrieval-tuning/';

    /**
     * @var array<string, int|float>
     */
    public const array L0_DEFAULT = ['agreement_boost' => 0.15, 'rrf_k' => 60, 'rrf_weight' => 0.25];

    /**
     * What is wrong with the profile's claim to be measured, one message per problem.
     *
     * @param  array<string, mixed>  $profile
     * @return list<string>
     */
    public static function violations(array $profile, string $module_root): array
    {
        $report_path = $profile['report'] ?? null;

        if ($report_path === null && self::restatesL0($profile)) {
            return [];
        }

        if (! is_string($report_path) || mb_trim($report_path) === '') {
            return ['The profile carries measured values but cites no report: add a `report` key naming a validated test:ai:tune-retrieval report.'];
        }

        if (! str_starts_with($report_path, self::REPORT_DIRECTORY)
            || ! str_ends_with($report_path, '.json')
            || str_contains($report_path, '..')
            || str_contains($report_path, '\\')) {
            return ['The report [' . $report_path . '] must stay under ' . self::REPORT_DIRECTORY . ' and be a .json file.'];
        }

        $file = mb_rtrim($module_root, '/') . '/' . $report_path;

        if (! is_file($file)) {
            return ['The report [' . $report_path . '] does not exist.'];
        }

        $report = json_decode((string) file_get_contents($file), true);

        if (! is_array($report)) {
            return ['The report [' . $report_path . '] is not valid JSON.'];
        }

        return [
            ...self::statusViolations($report),
            ...self::valueViolations($profile, $report),
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private static function restatesL0(array $profile): bool
    {
        $default = is_array($profile['default'] ?? null) ? $profile['default'] : [];

        foreach ($default as $name => $value) {
            if (! array_key_exists($name, self::L0_DEFAULT) || self::L0_DEFAULT[$name] !== $value) {
                return false;
            }
        }

        $classes = is_array($profile['classes'] ?? null) ? $profile['classes'] : [];

        return array_all($classes, static fn (mixed $parameters): bool => $parameters === []);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private static function statusViolations(array $report): array
    {
        $violations = [];
        $validation = is_array($report['validation'] ?? null) ? ($report['validation']['status'] ?? null) : null;
        $noise = is_array($report['noise'] ?? null) ? ($report['noise']['status'] ?? null) : null;

        if ($validation !== 'passed') {
            $violations[] = 'The report validation status is [' . (is_string($validation) ? $validation : 'missing') . ']: a profile needs a passed held-out validation.';
        }

        if ($noise !== 'passed') {
            $violations[] = 'The report noise status is [' . (is_string($noise) ? $noise : 'missing') . ']: a profile needs a gain over the noise margin.';
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private static function valueViolations(array $profile, array $report): array
    {
        $winner = is_array($report['winner'] ?? null) && is_array($report['winner']['params'] ?? null) ? $report['winner']['params'] : null;

        if ($winner === null) {
            return ['The report has no winner: there is nothing to commit from it.'];
        }

        $violations = [];
        $default = is_array($profile['default'] ?? null) ? $profile['default'] : [];

        if (! self::sameParameters($default, $winner)) {
            $violations[] = 'The profile default differs from the winner of the report.';
        }

        $profile_classes = is_array($profile['classes'] ?? null) ? $profile['classes'] : [];
        $report_classes = is_array($report['class_winners'] ?? null) ? $report['class_winners'] : [];

        foreach (QueryClass::cases() as $class) {
            $committed = is_array($profile_classes[$class->value] ?? null) ? $profile_classes[$class->value] : [];
            $measured = is_array($report_classes[$class->value] ?? null) ? $report_classes[$class->value] : [];

            if (! self::sameParameters($committed, $measured)) {
                $violations[] = 'The profile entry for [' . $class->value . '] differs from the class override of the report.';
            }
        }

        return $violations;
    }

    /**
     * @param  array<mixed>  $first
     * @param  array<mixed>  $second
     */
    private static function sameParameters(array $first, array $second): bool
    {
        ksort($first);
        ksort($second);

        if (array_keys($first) !== array_keys($second)) {
            return false;
        }

        // Numeric equality: a report written without JSON_PRESERVE_ZERO_FRACTION turns 1.0 into 1.
        foreach ($first as $name => $value) {
            if (! is_numeric($value) || ! is_numeric($second[$name]) || (float) $value !== (float) $second[$name]) {
                return false;
            }
        }

        return true;
    }
}
