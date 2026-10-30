<?php

declare(strict_types=1);

use Modules\Core\Tests\Support\RetrievalTuningProfileAudit;

/**
 * A committed profile with measured values must say where they come from: a `report` key naming a
 * report of `test:ai:tune-retrieval` kept under `docs/evaluations/retrieval-tuning/`, that passed the
 * held-out validation and the noise check, and whose winner is what the profile holds. Without
 * that, a hand-picked number is indistinguishable from a measurement. A profile that only
 * restates the L0 constants is exempt: it changes nothing.
 */
function retrieval_tuning_audit_root(): string
{
    $root = sys_get_temp_dir() . '/laraplate-tuning-profile-' . bin2hex(random_bytes(5));
    mkdir($root . '/docs/evaluations/retrieval-tuning', 0700, true);

    return $root;
}

function retrieval_tuning_audit_cleanup(string $root): void
{
    foreach (glob($root . '/docs/evaluations/retrieval-tuning/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($root . '/docs/evaluations/retrieval-tuning');
    @rmdir($root . '/docs/evaluations');
    @rmdir($root . '/docs');
    @rmdir($root);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function retrieval_tuning_audit_report(array $overrides = []): array
{
    return [
        'version' => '1',
        'winner' => ['params' => ['keyword_weight' => 0.5, 'vector_weight' => 0.2, 'hybrid_weight' => 0.3, 'rrf_k' => 20]],
        'validation' => ['status' => 'passed', 'delta' => 0.04],
        'noise' => ['status' => 'passed', 'gain' => 0.09, 'margin' => 0.05],
        'class_winners' => ['identifier' => ['keyword_weight' => 1.0, 'vector_weight' => 0.0, 'hybrid_weight' => 0.0]],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function retrieval_tuning_audit_profile(array $overrides = []): array
{
    return [
        'version' => '2026-11-01.measured',
        'report' => 'docs/evaluations/retrieval-tuning/2026-11-01-cms-contents.json',
        'default' => ['keyword_weight' => 0.5, 'vector_weight' => 0.2, 'hybrid_weight' => 0.3, 'rrf_k' => 20],
        'classes' => [
            'identifier' => ['keyword_weight' => 1.0, 'vector_weight' => 0.0, 'hybrid_weight' => 0.0],
            'short_keyword' => [],
            'multi_term' => [],
            'natural_language' => [],
        ],
        ...$overrides,
    ];
}

function retrieval_tuning_audit_write(string $root, array $report, string $name = '2026-11-01-cms-contents.json'): void
{
    file_put_contents($root . '/docs/evaluations/retrieval-tuning/' . $name, json_encode($report, JSON_THROW_ON_ERROR));
}

it('commits a profile that is either the L0 constants or backed by a validated report', function (): void {
    $profile = require dirname(__DIR__, 3) . '/config/search_tuning.php';

    expect(RetrievalTuningProfileAudit::violations($profile, dirname(__DIR__, 3)))->toBe([]);
});

it('exempts a profile that only restates the L0 constants from citing a report', function (): void {
    $profile = [
        'version' => 'x.l0',
        'default' => ['agreement_boost' => 0.15, 'rrf_k' => 60, 'rrf_weight' => 0.25],
        'classes' => ['identifier' => [], 'short_keyword' => [], 'multi_term' => [], 'natural_language' => []],
    ];

    expect(RetrievalTuningProfileAudit::violations($profile, '/nowhere'))->toBe([]);
});

it('asks a profile with measured values to cite a report', function (array $profile): void {
    unset($profile['report']);

    expect(RetrievalTuningProfileAudit::violations($profile, '/nowhere'))->toHaveCount(1)
        ->and(RetrievalTuningProfileAudit::violations($profile, '/nowhere')[0])->toContain('cites no report');
})->with([
    'a changed default' => [retrieval_tuning_audit_profile()],
    'a class override on the L0 constants' => [retrieval_tuning_audit_profile([
        'default' => ['agreement_boost' => 0.15, 'rrf_k' => 60, 'rrf_weight' => 0.25],
    ])],
    'a changed L0 constant' => [retrieval_tuning_audit_profile([
        'default' => ['agreement_boost' => 0.30],
        'classes' => [],
    ])],
]);

it('accepts a profile whose report passed both checks and holds the same values', function (): void {
    $root = retrieval_tuning_audit_root();

    try {
        retrieval_tuning_audit_write($root, retrieval_tuning_audit_report());

        expect(RetrievalTuningProfileAudit::violations(retrieval_tuning_audit_profile(), $root))->toBe([]);
    } finally {
        retrieval_tuning_audit_cleanup($root);
    }
});

it('rejects a report that does not back the profile', function (array $report, string $reason): void {
    $root = retrieval_tuning_audit_root();

    try {
        retrieval_tuning_audit_write($root, $report);

        $violations = RetrievalTuningProfileAudit::violations(retrieval_tuning_audit_profile(), $root);

        expect($violations)->toHaveCount(1)
            ->and($violations[0])->toContain($reason);
    } finally {
        retrieval_tuning_audit_cleanup($root);
    }
})->with([
    'held-out validation failed' => [retrieval_tuning_audit_report(['validation' => ['status' => 'failed']]), 'validation'],
    'held-out validation skipped' => [retrieval_tuning_audit_report(['validation' => ['status' => 'skipped']]), 'validation'],
    'held-out validation off' => [retrieval_tuning_audit_report(['validation' => ['status' => 'disabled']]), 'validation'],
    'gain within the noise' => [retrieval_tuning_audit_report(['noise' => ['status' => 'within_noise']]), 'noise'],
    'noise check off' => [retrieval_tuning_audit_report(['noise' => ['status' => 'disabled']]), 'noise'],
    'no noise check at all' => [array_diff_key(retrieval_tuning_audit_report(), ['noise' => true]), 'noise'],
    'no winner' => [retrieval_tuning_audit_report(['winner' => null]), 'winner'],
    'another default' => [retrieval_tuning_audit_report(['winner' => ['params' => ['keyword_weight' => 0.9]]]), 'default'],
    'another class override' => [retrieval_tuning_audit_report(['class_winners' => []]), 'identifier'],
    'a class override the profile lacks' => [retrieval_tuning_audit_report(['class_winners' => [
        'identifier' => ['keyword_weight' => 1.0, 'vector_weight' => 0.0, 'hybrid_weight' => 0.0],
        'multi_term' => ['rrf_k' => 10],
    ]]), 'multi_term'],
]);

it('rejects a report that is missing, unreadable or outside the evaluations directory', function (string $path, string $reason): void {
    $root = retrieval_tuning_audit_root();

    try {
        file_put_contents($root . '/docs/evaluations/retrieval-tuning/broken.json', '{not json');
        file_put_contents($root . '/outside.json', json_encode(retrieval_tuning_audit_report(), JSON_THROW_ON_ERROR));

        $violations = RetrievalTuningProfileAudit::violations(retrieval_tuning_audit_profile(['report' => $path]), $root);

        expect($violations)->toHaveCount(1)
            ->and($violations[0])->toContain($reason);
    } finally {
        @unlink($root . '/outside.json');
        retrieval_tuning_audit_cleanup($root);
    }
})->with([
    'a file that is not there' => ['docs/evaluations/retrieval-tuning/missing.json', 'does not exist'],
    'a file that is not JSON' => ['docs/evaluations/retrieval-tuning/broken.json', 'not valid JSON'],
    'a path above the directory' => ['docs/evaluations/retrieval-tuning/../../../outside.json', 'must stay under'],
    'a path elsewhere' => ['outside.json', 'must stay under'],
    'a report that is not JSON by name' => ['docs/evaluations/retrieval-tuning/report.txt', 'must stay under'],
]);
