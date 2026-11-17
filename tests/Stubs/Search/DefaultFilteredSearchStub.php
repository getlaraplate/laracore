<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder as ScoutBuilder;
use Modules\Core\Contracts\ProvidesDefaultSearchFilters;
use Modules\Core\Models\Concerns\HasValidity;
use Modules\Core\Search\Engines\DatabaseEngine;
use Modules\Core\Search\Schema\FieldDefinition;
use Modules\Core\Search\Schema\FieldType;
use Modules\Core\Search\Schema\IndexType;
use Modules\Core\Search\Schema\SchemaDefinition;
use Modules\Core\Search\Traits\Searchable as CoreSearchable;
use Override;

/**
 * A record that can be a variant, as a CMS content can be the body of an extension: a row with `variant` set is
 * hidden from every read by a global scope, and kept out of the generic search by a default search filter.
 *
 * The engine is the database engine reading the table without that scope, as an external index holds every row:
 * only the default filter keeps a variant from coming back from the engine, and the reload of the hits drops the
 * variants that come back anyway.
 */
final class DefaultFilteredSearchStub extends Model implements ProvidesDefaultSearchFilters
{
    use CoreSearchable {
        getSearchMapping as getSearchMappingTrait;
    }
    use HasValidity;

    public const string TABLE = 'default_filtered_search_stubs';

    public const string HIDES_VARIANTS = 'hides_variants';

    #[Override]
    protected $table = self::TABLE;

    #[Override]
    protected $guarded = [];

    /**
     * @return array<string, scalar|null>
     */
    #[Override]
    public function defaultSearchFilters(): array
    {
        return ['variant' => null];
    }

    public function searchableUsing(): DatabaseEngine
    {
        return new DatabaseEngine();
    }

    /**
     * The rows the engine searches: every row, variants included, as an external index holds them.
     *
     * @param  ScoutBuilder<self>  $builder
     * @return Builder<self>
     */
    public function newScoutQuery(ScoutBuilder $builder): Builder
    {
        return $this->newQuery()->withoutGlobalScope(self::HIDES_VARIANTS);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->getKey(),
            'title' => (string) $this->getAttribute('title'),
            'variant' => $this->getAttribute('variant'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSearchMapping(?SchemaDefinition $schema = null): array
    {
        $schema ??= new SchemaDefinition($this->getTable());
        $schema->addField(new FieldDefinition('id', FieldType::Integer, [IndexType::Filterable]));
        $schema->addField(new FieldDefinition('title', FieldType::Text, [IndexType::Searchable]));
        $schema->addField(new FieldDefinition('variant', FieldType::Keyword, [IndexType::Filterable]));

        return $this->getSearchMappingTrait($schema);
    }

    #[Override]
    protected static function booted(): void
    {
        self::addGlobalScope(self::HIDES_VARIANTS, static function (Builder $query): void {
            $query->whereNull($query->getModel()->qualifyColumn('variant'));
        });
    }
}
