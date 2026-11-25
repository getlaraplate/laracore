<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Contracts\ISearchableModel;
use Modules\Core\Search\Traits\Searchable;
use Modules\Core\SoftDeletes\SoftDeletes;

/**
 * Searchable, soft-deletable model with timestamps, for driving `scout:sync` against a real table. Its engine is a
 * recording double, so nothing reaches a search server.
 */
final class SyncSearchStubModel extends Model implements ISearchableModel
{
    use Searchable;
    use SoftDeletes;

    public const string TABLE = 'core_sync_search_stub_rows';

    protected $table = self::TABLE;

    protected $guarded = [];

    public static function createTable(): void
    {
        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
            $table->boolean('is_deleted')->storedAs('deleted_at IS NOT NULL');
        });
    }

    public static function dropTable(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    public function searchableAs(): string
    {
        return 'core_sync_search_stub';
    }

    public function searchableUsing(): RecordingSearchEngineStub
    {
        return new RecordingSearchEngineStub;
    }
}
