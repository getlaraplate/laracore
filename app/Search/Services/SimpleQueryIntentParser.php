<?php

declare(strict_types=1);

namespace Modules\Core\Search\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Search\Contracts\IQueryIntentParser;

/**
 * Basic keyword extraction parser without AI/LLM dependency.
 *
 * Splits the query by whitespace, removes common English and Italian
 * stopwords, and returns structured intent data. The dates the query names
 * are read by {@see DateExpressionResolver}, relative to today.
 */
final readonly class SimpleQueryIntentParser implements IQueryIntentParser
{
    /**
     * @var list<string>
     */
    private const array STOPWORDS = [
        // English
        'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
        'of', 'with', 'by', 'from', 'is', 'it', 'as', 'be', 'was', 'are',
        'been', 'has', 'have', 'had', 'do', 'does', 'did', 'will', 'would',
        'could', 'should', 'may', 'might', 'not', 'no', 'so', 'if', 'then',
        'than', 'that', 'this', 'these', 'those', 'what', 'which', 'who',
        'how', 'when', 'where', 'why', 'all', 'each', 'every', 'both',
        'few', 'more', 'most', 'other', 'some', 'such', 'only', 'own',
        'same', 'too', 'very', 'can', 'just', 'about', 'above', 'after',
        'again', 'also', 'any', 'because', 'before', 'between', 'here',
        'into', 'over', 'through', 'under', 'up', 'out',
        // Italian
        'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una', 'di', 'del',
        'dello', 'della', 'dei', 'degli', 'delle', 'da', 'dal', 'dallo',
        'dalla', 'dai', 'dagli', 'dalle', 'su', 'sul', 'sullo', 'sulla',
        'sui', 'sugli', 'sulle', 'per', 'con', 'tra', 'fra', 'che', 'chi',
        'cui', 'non', 'né', 'se', 'come', 'dove', 'quando', 'perché',
        'anche', 'ancora', 'ma', 'più', 'molto', 'poco', 'tutto', 'tutti',
        'ogni', 'altro', 'è', 'sono', 'ha', 'hanno', 'era', 'erano', 'nel',
        'nello', 'nella', 'nei', 'negli', 'nelle', 'al', 'allo', 'alla',
        'ai', 'agli', 'alle', 'ed', 'si', 'ci', 'vi', 'mi', 'ti',
    ];

    public function __construct(
        private DateExpressionResolver $dates = new DateExpressionResolver,
    ) {}

    /**
     * @return array{keywords: list<string>, date_range: ?array<string, string>, recent: bool, query: array{expanded: string}}
     */
    public function parse(string $query): array
    {
        $words = preg_split('/\s+/', mb_strtolower(mb_trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopword_set = array_flip(self::STOPWORDS);

        $keywords = [];

        foreach ($words as $word) {
            if (! isset($stopword_set[$word]) && mb_strlen($word) > 1) {
                $keywords[] = $word;
            }
        }

        return [
            'keywords' => array_values(array_unique($keywords)),
            'date_range' => $this->dates->resolve($query, CarbonImmutable::now(), LocaleContext::get()),
            'recent' => $this->dates->asksForRecent($query, LocaleContext::get()),
            'query' => [
                'expanded' => $query,
            ],
        ];
    }
}
