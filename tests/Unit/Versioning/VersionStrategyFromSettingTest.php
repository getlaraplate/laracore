<?php

declare(strict_types=1);

use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Versioning\VersioningSettingConfirmation;

/*
 * A `versioning.strategy.{table}` value: `false` and an empty value mean versioning is off, a strategy name its
 * strategy, anything else fails where it is read. An empty value used to fail on every write of the model.
 */

it('reads a versioning setting value as a strategy or as off', function (mixed $value, VersionStrategy|false $expected): void {
    expect(VersionStrategy::fromSetting($value))->toBe($expected)
        ->and(VersioningSettingConfirmation::isOff($value))->toBe($expected === false);
})->with([
    'false' => [false, false],
    'null, an empty save' => [null, false],
    'an empty string' => ['', false],
    'a strategy name' => ['DIFF', VersionStrategy::DIFF],
    'a strategy' => [VersionStrategy::SNAPSHOT, VersionStrategy::SNAPSHOT],
]);

it('refuses a value that is no strategy', function (): void {
    VersionStrategy::fromSetting('sometimes');
})->throws(ValueError::class);
