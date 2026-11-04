<?php

declare(strict_types=1);

namespace Modules\Core\Tests;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The lightweight container built once per process by minimal-test-environment.php.
     */
    private static ?Container $minimal_container = null;

    protected function setUp(): void
    {
        self::bootstrapMinimalTestEnvironment();
        parent::setUp();
    }

    /**
     * Registers config(), facades, module_path(), fake(), and fake fixture autoloading for lightweight tests.
     *
     * The container is made global again before every test, and the facades forget what they resolved:
     * a full-application test run earlier in the same process leaves its flushed application as the global
     * container and as the resolved root of facades such as App, because Laravel only clears them in setUp.
     */
    private static function bootstrapMinimalTestEnvironment(): void
    {
        self::$minimal_container ??= require __DIR__ . '/minimal-test-environment.php';

        Container::setInstance(self::$minimal_container);
        Facade::setFacadeApplication(self::$minimal_container);
        Facade::clearResolvedInstances();
    }
}
