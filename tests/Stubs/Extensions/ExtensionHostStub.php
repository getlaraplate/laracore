<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Extensions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder as ScoutBuilder;
use Modules\Core\Contracts\IExtendableModel;
use Modules\Core\Models\Concerns\HasExtensions;
use Modules\Core\Search\Engines\DatabaseEngine;
use Modules\Core\Search\Extensions\HidesExtendedRows;
use Modules\Core\Search\Schema\FieldDefinition;
use Modules\Core\Search\Schema\FieldType;
use Modules\Core\Search\Schema\IndexType;
use Modules\Core\Search\Schema\SchemaDefinition;
use Modules\Core\Search\Traits\Searchable as CoreSearchable;
use Override;

/**
 * A host of the extension contracts on its own table: a row with `extension_type` set is owned by an extender. It
 * declares no default search filter, so only the host contract keeps extended rows out of a search.
 *
 * The engine is the database engine reading the table without the hiding scope, as an external index holds every
 * row.
 */
final class ExtensionHostStub extends Model implements IExtendableModel
{
    use CoreSearchable {
        getSearchMapping as getSearchMappingTrait;
    }
    use HasExtensions;

    public const string TABLE = 'extension_host_stubs';

    #[Override]
    protected $table = self::TABLE;

    #[Override]
    protected $guarded = [];

    public function extensionTypeColumn(): string
    {
        return 'extension_type';
    }

    public function searchableUsing(): DatabaseEngine
    {
        return new DatabaseEngine();
    }

    /**
     * @param  ScoutBuilder<self>  $builder
     * @return Builder<self>
     */
    public function newScoutQuery(ScoutBuilder $builder): Builder
    {
        return $this->newQuery()->withoutGlobalScope(HidesExtendedRows::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->getKey(),
            'title' => (string) $this->getAttribute('title'),
            'extension_type' => $this->getAttribute('extension_type'),
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
        $schema->addField(new FieldDefinition('extension_type', FieldType::Keyword, [IndexType::Filterable]));

        return $this->getSearchMappingTrait($schema);
    }
}
