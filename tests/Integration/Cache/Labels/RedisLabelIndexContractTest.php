<?php

declare(strict_types=1);

use Modules\Core\Tests\Stubs\Cache\RedisLabelIndexFactory;

afterEach(fn () => RedisLabelIndexFactory::cleanup());

dataset('redis index', [
    'redis' => [fn () => RedisLabelIndexFactory::make()],
]);

foreach (require __DIR__ . '/../../../Support/LabelIndexContractCases.php' as $name => $case) {
    it($name, $case)->with('redis index')->group('redis');
}
