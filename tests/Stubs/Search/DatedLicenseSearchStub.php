<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Search\Engines\DatabaseEngine;
use Modules\Core\Search\Traits\Searchable as CoreSearchable;
use Override;

/**
 * A license searchable through the database engine that declares `valid_from` as its search date field, and
 * declares it filterable so the engine takes the date filter of a query.
 */
final class DatedLicenseSearchStub extends Model
{
    use CoreSearchable;

    #[Override]
    protected $table = CoreTables::Licenses->value;

    #[Override]
    protected $guarded = [];

    protected ?string $searchDateField = 'valid_from';

    public function searchableUsing(): DatabaseEngine
    {
        return new DatabaseEngine();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->getKey(),
            'uuid' => (string) $this->getAttribute('uuid'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSearchMapping(): array
    {
        return [
            'columns' => [
                'valid_from' => ['type' => 'timestamp', 'filterable' => true],
            ],
        ];
    }
}
