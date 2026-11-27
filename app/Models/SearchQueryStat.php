<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Enums\CoreTables;
use Override;

/**
 * One day of one normalized search query, per locale and searched model: how many times it was searched,
 * how many of those found few results, and the sum of the results. It holds no user, IP or session.
 *
 * @property int $id
 * @property string $query
 * @property string $locale
 * @property string $model
 * @property Illuminate\Support\Carbon $day
 * @property int $searches
 * @property int $low_result_searches
 * @property int $result_sum
 */
final class SearchQueryStat extends Model
{
    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'query',
        'locale',
        'model',
        'day',
        'searches',
        'low_result_searches',
        'result_sum',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::SearchQueryStats->value;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'day' => 'date:Y-m-d',
            'searches' => 'integer',
            'low_result_searches' => 'integer',
            'result_sum' => 'integer',
        ];
    }
}
