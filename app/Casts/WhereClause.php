<?php

declare(strict_types=1);

namespace Modules\Core\Casts;

enum WhereClause: string
{
    case And = 'and';
    case Or = 'or';

    /**
     * The group operator of whatever a request sent as `operator`: an operator already read, or a string in any letter
     * case. Nothing usable (an unknown word, null, a number, a list) is null: the caller refuses it.
     */
    public static function tryFromRequestValue(mixed $operator): ?self
    {
        return match (true) {
            $operator instanceof self => $operator,
            is_string($operator) => self::tryFrom(mb_strtolower($operator)),
            default => null,
        };
    }

    /**
     * What a request may send as a group operator, for the message that refuses an unknown one.
     */
    public static function requestVocabulary(): string
    {
        return implode(', ', array_map(static fn (self $operator): string => $operator->value, self::cases()));
    }
}
