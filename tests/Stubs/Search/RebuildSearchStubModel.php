<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\Core\Contracts\ISearchableModel;
use Modules\Core\Search\Traits\Searchable;

/**
 * Searchable model for `scout:rebuild` tests. Each concrete subclass is bound to its own
 * {@see RebuildRecordingEngineStub}, and the reindex is reported to that engine instead of
 * writing documents, so a test sees what the command ran per model without a search server.
 */
abstract class RebuildSearchStubModel extends Model implements ISearchableModel
{
    use Searchable;

    public const string TABLE = 'core_rebuild_stubs';

    protected $table = self::TABLE;

    protected $guarded = [];

    /**
     * @var array<class-string, RebuildRecordingEngineStub>
     */
    private static array $engines = [];

    public static function bindEngine(RebuildRecordingEngineStub $engine): void
    {
        self::$engines[static::class] = $engine;
    }

    public static function forgetEngines(): void
    {
        self::$engines = [];
    }

    public static function engine(): RebuildRecordingEngineStub
    {
        return self::$engines[static::class] ?? throw new LogicException('No engine bound for ' . static::class);
    }

    public static function reindex(?int $chunk = null): void
    {
        static::engine()->reindex();
    }

    public function searchableUsing(): RebuildRecordingEngineStub
    {
        return static::engine();
    }

    /**
     * @return array<string, mixed>
     */
    public function getSearchMapping(): array
    {
        return ['mappings' => ['properties' => []]];
    }
}
