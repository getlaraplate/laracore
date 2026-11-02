<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Illuminate\Database\Eloquent\Model;

/**
 * Model whose `searchableUsing()` returns whatever engine double the test hands it and whose search date field
 * is the one the test names, so the date filter of {@see \Modules\Core\Search\Services\AdvancedSearchService}
 * can be driven without Scout.
 */
final class DatedEngineBoundStubModel extends Model
{
    public function __construct(private readonly mixed $engine = null, private readonly ?string $dateField = null)
    {
        parent::__construct();
    }

    public function searchableUsing(): mixed
    {
        return $this->engine;
    }

    public function searchDateField(): ?string
    {
        return $this->dateField;
    }
}
