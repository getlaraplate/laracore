<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * A model whose Typesense mapping holds two top-level dates (`valid_from`, `valid_to`, `int64` as the translator
 * writes a date) next to a string and an integer field, for the date handling of the Typesense engine.
 */
final class TypesenseDatedStub extends Model
{
    #[Override]
    protected $table = 'typesense_dated_stubs';

    public function searchableAs(): string
    {
        return 'typesense_dated_stubs';
    }

    /**
     * @return array{fields: list<array{name: string, type: string}>}
     */
    public function getSearchMapping(): array
    {
        return [
            'fields' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'extended_type', 'type' => 'string'],
                ['name' => 'company_id', 'type' => 'int32'],
                ['name' => 'valid_from', 'type' => 'int64'],
                ['name' => 'valid_to', 'type' => 'int64'],
            ],
        ];
    }
}
