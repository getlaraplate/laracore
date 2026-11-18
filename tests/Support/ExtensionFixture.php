<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Search\Extensions\ExtensionRegistry;
use Modules\Core\Search\Schema\FieldType;
use Modules\Core\Tests\Stubs\Extensions\ExtensionHostStub;
use Modules\Core\Tests\Stubs\Extensions\FirstExtenderStub;
use Modules\Core\Tests\Stubs\Extensions\SecondExtenderStub;

/**
 * The tables of {@see ExtensionHostStub} and its two extenders, a fresh registry holding both, and rows.
 */
final class ExtensionFixture
{
    public static function setUp(): void
    {
        Schema::create(ExtensionHostStub::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('extension_type')->nullable();
            $table->timestamps();
        });

        foreach ([FirstExtenderStub::TABLE, SecondExtenderStub::TABLE] as $extender_table) {
            Schema::create($extender_table, static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('host_id')->unique();
                $table->string('kind')->nullable();
                $table->timestamps();
            });
        }

        SecondExtenderStub::$mapping = ['kind' => ['type' => FieldType::Keyword, 'filterable' => true]];

        $registry = new ExtensionRegistry();
        $registry->register(ExtensionHostStub::class, FirstExtenderStub::ALIAS, FirstExtenderStub::class);
        $registry->register(ExtensionHostStub::class, SecondExtenderStub::ALIAS, SecondExtenderStub::class);
        app()->instance(ExtensionRegistry::class, $registry);
    }

    public static function tearDown(): void
    {
        Schema::dropIfExists(SecondExtenderStub::TABLE);
        Schema::dropIfExists(FirstExtenderStub::TABLE);
        Schema::dropIfExists(ExtensionHostStub::TABLE);
    }

    /**
     * A host row, plain or owned by the extender of the alias (which then gets its extender row).
     */
    public static function row(string $title, ?string $alias = null, ?string $kind = null): ExtensionHostStub
    {
        $host = new ExtensionHostStub();
        $host->forceFill(['title' => $title, 'extension_type' => $alias])->save();

        $extender = match ($alias) {
            FirstExtenderStub::ALIAS => new FirstExtenderStub(),
            SecondExtenderStub::ALIAS => new SecondExtenderStub(),
            default => null,
        };

        $extender?->forceFill(['host_id' => $host->getKey(), 'kind' => $kind])->save();

        return $host;
    }
}
