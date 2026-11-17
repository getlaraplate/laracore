<?php

declare(strict_types=1);

namespace Modules\Core\Support;

/**
 * The plain text of an Editor field ({@see \Modules\Core\Casts\FieldType::Editor}): an Editor.js document whose
 * `blocks` hold text in different shapes (the same `data` key is a string in one block type and an object in
 * another). Every reader that needs the words of such a field (the search index, the reranker, the reading
 * statistics) reads them here, so they agree on which parts of a block are text.
 *
 * Moved from CMS `ReadingStatistics`: the Editor field type is Core's, so its plain text is not CMS knowledge.
 * Spec `docs/superpowers/specs/2026-11-17-extended-entity-search-design.md`, XS15.
 */
final readonly class EditorPlainText
{
    /**
     * The searchable text of a stored field value: an Editor document becomes its plain text, a string loses its
     * line breaks and tabs, anything else is returned as it is.
     */
    public static function fromValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return (string) preg_replace('/\n|\r|\t/', '', $value);
        }

        $blocks = match (true) {
            is_array($value) && array_key_exists('blocks', $value) => $value['blocks'],
            is_object($value) && property_exists($value, 'blocks') => $value->blocks,
            default => null,
        };

        return is_iterable($blocks) ? self::fromBlocks($blocks) : $value;
    }

    /**
     * The readable text of Editor.js blocks as one plain string: the text-bearing parts of each block (text,
     * caption, list items, table cells, raw html), tags removed, entities decoded, whitespace collapsed.
     * Punctuation is kept.
     *
     * @param  iterable<int, mixed>  $blocks
     */
    public static function fromBlocks(iterable $blocks): string
    {
        $chunks = [];

        foreach (self::blocks($blocks) as $block) {
            foreach (self::segments($block) as $segment) {
                $plain = preg_replace('/<[^>]*>/u', ' ', self::stripFindMarks($segment)) ?? '';
                $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $plain = mb_trim(preg_replace('/\s+/u', ' ', $plain) ?? '');

                if ($plain !== '') {
                    $chunks[] = $plain;
                }
            }
        }

        return implode(' ', $chunks);
    }

    /**
     * The blocks of a document as `type` and string-keyed `data`, whatever their decoded shape (arrays, or objects
     * from a JSON decode). A block without a type is a paragraph.
     *
     * @param  iterable<int, mixed>  $blocks
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public static function blocks(iterable $blocks): array
    {
        $out = [];

        foreach ($blocks as $block) {
            $out[] = [
                'type' => self::blockType($block),
                'data' => self::blockData($block),
            ];
        }

        return $out;
    }

    /**
     * The HTML segments of a block that hold text: `text`, `caption`, the list `items`, the cells of a `table`,
     * the `html` of a `raw` block.
     *
     * @param  array{type: string, data: array<string, mixed>}  $block
     * @return list<string>
     */
    public static function segments(array $block): array
    {
        $data = $block['data'];
        $type = $block['type'];
        $segments = [];

        if (isset($data['text']) && is_string($data['text']) && $data['text'] !== '') {
            $segments[] = $data['text'];
        }

        if (isset($data['caption']) && is_string($data['caption']) && $data['caption'] !== '') {
            $segments[] = $data['caption'];
        }

        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                if (is_string($item) && $item !== '') {
                    $segments[] = $item;
                }

                if (is_array($item) && isset($item['text']) && is_string($item['text']) && $item['text'] !== '') {
                    $segments[] = $item['text'];
                }
            }
        }

        if ($type === 'table' && isset($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                foreach ($row as $cell) {
                    if (is_string($cell) && $cell !== '') {
                        $segments[] = $cell;
                    }
                }
            }
        }

        if ($type === 'raw' && isset($data['html']) && is_string($data['html']) && $data['html'] !== '') {
            $segments[] = $data['html'];
        }

        return $segments;
    }

    /**
     * The HTML without the find-and-replace marks of the editor: a marked span keeps its text, and a struck-out
     * replacement keeps the struck text.
     */
    public static function stripFindMarks(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $previous = '';
        $current = $html;
        $guard = 0;

        while ($previous !== $current && $guard < 50) {
            $previous = $current;
            $guard++;
            $current = (string) preg_replace_callback(
                '/<span\b[^>]*\bdata-editor-find-mark="1"[^>]*>(.*?)<\/span>/is',
                static function (array $matches): string {
                    $inner = $matches[1];

                    if (preg_match('/<del\b[^>]*\beditor-find-strike\b[^>]*>(.*?)<\/del>/is', $inner, $del_match)) {
                        return $del_match[1];
                    }

                    return $inner;
                },
                $current,
            );
        }

        return $current;
    }

    private static function blockType(mixed $block): string
    {
        if (is_array($block)) {
            $type = $block['type'] ?? 'paragraph';
        } elseif (is_object($block)) {
            $type = $block->type ?? 'paragraph';
        } else {
            return 'paragraph';
        }

        return is_string($type) && $type !== '' ? $type : 'paragraph';
    }

    /**
     * @return array<string, mixed>
     */
    private static function blockData(mixed $block): array
    {
        if (is_array($block)) {
            $data = $block['data'] ?? [];
        } elseif (is_object($block)) {
            $data = $block->data ?? [];
        } else {
            return [];
        }

        if (is_object($data)) {
            $json = json_encode($data);
            $data = is_string($json) ? json_decode($json, true) : null;
        }

        if (! is_array($data)) {
            return [];
        }

        $normalized = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
