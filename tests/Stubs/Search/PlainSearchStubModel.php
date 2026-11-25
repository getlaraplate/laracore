<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\ISearchableModel;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\Traits\Searchable;

/**
 * A searchable model that cannot be soft-deleted: Core's Searchable without SoftDeletes, on a plain Eloquent model.
 */
class PlainSearchStubModel extends Model implements ISearchableModel
{
    use Searchable;

    public $timestamps = false;

    protected $table = 'core_test_plain_search_stub';

    protected $guarded = [];

    private ISearchEngine $engine;

    public function searchableUsing(): ISearchEngine
    {
        return $this->engine;
    }

    public function withEngine(ISearchEngine $engine): static
    {
        $this->engine = $engine;

        return $this;
    }
}
