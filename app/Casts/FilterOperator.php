<?php

declare(strict_types=1);

namespace Modules\Core\Casts;

enum FilterOperator: string
{
    case Great = '>';
    case GreatEquals = '>=';
    case Less = '<';
    case LessEquals = '<=';
    case Like = 'like';
    case NotLike = 'not like';
    case Equals = '=';
    case In = 'in';
    case NotEquals = '!=';
    case Between = 'between';

    /**
     * The operator a request names, in either spelling: the symbols of this enum (`=`, `!=`, `>`, `>=`, `<`, `<=`) or
     * the names of {@see RequestFilterOperator} (`eq`, `ne`, `gt`, `ge`, `lt`, `le`). `like`, `not like`, `in` and
     * `between` are spelled the same in both. Anything else is null: the caller refuses it.
     */
    public static function tryFromRequestOperator(RequestFilterOperator|string $operator): ?self
    {
        return match ($operator) {
            RequestFilterOperator::Great, RequestFilterOperator::Great->value => self::Great,
            RequestFilterOperator::GreatEquals, RequestFilterOperator::GreatEquals->value => self::GreatEquals,
            RequestFilterOperator::Less, RequestFilterOperator::Less->value => self::Less,
            RequestFilterOperator::LessEquals, RequestFilterOperator::LessEquals->value => self::LessEquals,
            RequestFilterOperator::Like, RequestFilterOperator::Like->value => self::Like,
            RequestFilterOperator::NotLike, RequestFilterOperator::NotLike->value => self::NotLike,
            RequestFilterOperator::Equals, RequestFilterOperator::Equals->value => self::Equals,
            RequestFilterOperator::In, RequestFilterOperator::In->value => self::In,
            RequestFilterOperator::NotEquals, RequestFilterOperator::NotEquals->value => self::NotEquals,
            RequestFilterOperator::Between, RequestFilterOperator::Between->value => self::Between,
            default => is_string($operator) ? self::tryFrom($operator) : null,
        };
    }

    /**
     * The operator of whatever a request sent as `operator`: an operator already read, a string in either spelling, or
     * nothing usable (null, a number, a list), which is null.
     */
    public static function tryFromRequestValue(mixed $operator): ?self
    {
        return match (true) {
            $operator instanceof self => $operator,
            $operator instanceof RequestFilterOperator, is_string($operator) => self::tryFromRequestOperator($operator),
            default => null,
        };
    }

    /**
     * What a request may send as an operator, each as `symbol (name)` or once where the two spellings are the same,
     * for the message that refuses an unknown one.
     */
    public static function requestVocabulary(): string
    {
        $spellings = [];

        foreach (self::cases() as $operator) {
            $name = RequestFilterOperator::tryFromFilterOperator($operator)?->value;
            $spellings[] = $name === null || $name === $operator->value ? $operator->value : sprintf('%s (%s)', $operator->value, $name);
        }

        return implode(', ', $spellings);
    }
}
