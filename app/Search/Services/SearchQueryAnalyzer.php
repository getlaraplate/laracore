<?php

declare(strict_types=1);

namespace Modules\Core\Search\Services;

use Modules\Core\Search\DTOs\AnalyzedSearchToken;
use Modules\Core\Search\DTOs\SearchQueryAnalysis;
use Modules\Core\Search\Enums\SearchTokenKind;

/**
 * Reads a keyword query: its tokens, which of them carry meaning, and which are codes to keep exact.
 *
 * It also takes the request words out of the query ({@see self::withoutRequestWords()}): "find me", "articoli
 * su" say what the reader wants, not what the documents contain, and as keyword terms they would be required
 * of every match.
 */
final readonly class SearchQueryAnalyzer
{
    /**
     * Latin vowels and the accented forms a request word may be written with, in either direction.
     *
     * @var array<string, string>
     */
    private const array ACCENTED_VOWELS = [
        'a' => 'aàáâä',
        'e' => 'eèéêë',
        'i' => 'iìíîï',
        'o' => 'oòóôö',
        'u' => 'uùúûü',
    ];

    /**
     * @var list<string>
     */
    private const array STOPWORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'in', 'is', 'it', 'of', 'on', 'or', 'the', 'to', 'with',
        'a', 'ai', 'al', 'alla', 'alle', 'anche', 'che', 'con', 'da', 'dal', 'dei', 'del', 'della', 'di', 'e', 'ed', 'gli', 'i',
        'il', 'in', 'la', 'le', 'lo', 'nel', 'nella', 'non', 'o', 'per', 'sono', 'su', 'tra', 'un', 'una', 'uno',
    ];

    public function analyze(string $query, int $minimumTermLength = 4): SearchQueryAnalysis
    {
        $parts = preg_split('/\s+/u', mb_trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopwords = array_flip(self::STOPWORDS);
        $tokens = [];
        $significant = 0;
        $protected = 0;
        $eligible = 0;

        foreach (array_values($parts) as $position => $original) {
            $normalized = mb_strtolower(mb_trim($original, " \t\n\r\0\x0B.,;:!?()[]{}\"'"));
            $kind = $this->classify($original, $normalized, $minimumTermLength);
            $is_significant = $normalized !== '' && ! isset($stopwords[$normalized]);
            $token = new AnalyzedSearchToken($position, $original, $normalized, $kind, $is_significant);
            $tokens[] = $token;

            if (! $is_significant) {
                continue;
            }

            $significant++;

            if ($token->protectedByDefault()) {
                $protected++;
            } else {
                $eligible++;
            }
        }

        return new SearchQueryAnalysis($query, $tokens, $significant, $protected, $eligible);
    }

    /**
     * The query without the request words of the locale (`core.search.request_words`, a list of words and
     * phrases per language), for the keyword search only: the vector search embeds the whole question.
     *
     * A word or phrase matches whole, whatever its case, with or without the accents of its vowels and with
     * either apostrophe, so "c'e" matches "c'è". Longer phrases are taken out first. The list of the locale's
     * language applies (`it_IT` reads `it`), no other. When nothing meaningful would be left (only request
     * words and stopwords), the query is returned unchanged: a keyword search never runs on an empty query.
     */
    public function withoutRequestWords(string $query, string $locale): string
    {
        $phrases = $this->requestWords($locale);

        if ($phrases === [] || mb_trim($query) === '') {
            return $query;
        }

        $text = $query;

        foreach ($phrases as $phrase) {
            $text = preg_replace($this->requestWordPattern($phrase), ' ', $text) ?? $text;
        }

        $text = mb_trim(preg_replace('/\s+/u', ' ', $text) ?? $text, " \t\n\r\0\x0B,;:");

        return $this->analyze($text)->significantTokenCount === 0 ? $query : $text;
    }

    /**
     * The request words of the locale's language, longest first.
     *
     * @return list<string>
     */
    private function requestWords(string $locale): array
    {
        $configured = config('core.search.request_words');
        $language = mb_strtolower(preg_split('/[-_]/', mb_trim($locale))[0] ?? '');
        $words = is_array($configured) && is_array($configured[$language] ?? null) ? $configured[$language] : [];
        $phrases = [];

        foreach ($words as $word) {
            if (is_string($word) && mb_trim($word) !== '') {
                $phrases[] = mb_strtolower(mb_trim($word));
            }
        }

        usort($phrases, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        return array_values(array_unique($phrases));
    }

    private function requestWordPattern(string $phrase): string
    {
        $words = preg_split('/\s+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = [];

        foreach ($words as $word) {
            $part = '';

            foreach (mb_str_split($word) as $character) {
                $part .= $this->requestWordCharacter($character);
            }

            $parts[] = $part;
        }

        return '/(?<![\p{L}\p{N}])' . implode('\s+', $parts) . '(?![\p{L}\p{N}])/iu';
    }

    private function requestWordCharacter(string $character): string
    {
        if ($character === "'" || $character === '’') {
            return "['’]";
        }

        foreach (self::ACCENTED_VOWELS as $forms) {
            if (str_contains($forms, $character)) {
                return '[' . $forms . ']';
            }
        }

        return preg_quote($character, '/');
    }

    private function classify(string $original, string $normalized, int $minimumTermLength): SearchTokenKind
    {
        if (filter_var($original, FILTER_VALIDATE_EMAIL) !== false) {
            return SearchTokenKind::Email;
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $original) === 1) {
            return SearchTokenKind::Uuid;
        }

        if ($normalized !== '' && ctype_digit($normalized)) {
            return SearchTokenKind::Numeric;
        }

        if (preg_match('/[\p{L}\d]+[-_\/.][\p{L}\d_\/.-]*/u', $original) === 1
            || (preg_match('/\d/u', $original) === 1 && preg_match('/\p{L}/u', $original) === 1)) {
            return SearchTokenKind::StructuredIdentifier;
        }

        if (mb_strlen($original) >= 2
            && mb_strlen($original) <= 8
            && preg_match('/^[\p{Lu}\d]+$/u', $original) === 1
            && preg_match('/\p{Lu}/u', $original) === 1) {
            return SearchTokenKind::Acronym;
        }

        if ($minimumTermLength > mb_strlen($normalized)) {
            return SearchTokenKind::Short;
        }

        return SearchTokenKind::Word;
    }
}
