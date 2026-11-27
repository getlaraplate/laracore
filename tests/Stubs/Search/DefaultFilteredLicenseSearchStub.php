<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\ProvidesDefaultSearchFilters;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Concerns\HasValidity;
use Modules\Core\Search\Engines\DatabaseEngine;
use Modules\Core\Search\Traits\Searchable as CoreSearchable;
use Override;

/**
 * A license searchable through the database engine that declares a default search filter: only licenses
 * without an end date are eligible unless a request says otherwise. `valid_to` is declared filterable so
 * the engine takes a request filter on it.
 */
final class DefaultFilteredLicenseSearchStub extends Model implements ProvidesDefaultSearchFilters
{
    use CoreSearchable;
    use HasValidity;

    #[Override]
    protected $table = CoreTables::Licenses->value;

    #[Override]
    protected $guarded = [];

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
            'valid_to' => $this->getAttribute('valid_to'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSearchMapping(): array
    {
        return [
            'columns' => [
                'valid_to' => ['type' => 'timestamp', 'filterable' => true],
            ],
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    #[Override]
    public function defaultSearchFilters(): array
    {
        return ['valid_to' => null];
    }
}
