<?php

namespace App\Support;

class PlainText
{
    public static function fromHtml(?string $html): ?string
    {
        if (blank($html)) {
            return null;
        }

        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = preg_replace('/<(?:style|script)\b[^>]*>.*?<\/(?:style|script)>/is', '', $html) ?? $html;
        $html = preg_replace('/<\/(?:p|div|li|h[1-6]|tr|blockquote)>/i', "\n\n", $html) ?? $html;
        $html = preg_replace('/<(?:br|hr)\s*\/?>/i', "\n", $html) ?? $html;

        $text = strip_tags($html);
        $text = self::stripBareCssRules($text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    private static function stripBareCssRules(string $text): string
    {
        $text = preg_replace('/@[\w-]+\s*\{(?:[^{}]|\{[^}]*\})*\}/is', '', $text) ?? $text;

        return preg_replace('/(?:[a-z][\w#.\[*:,\s>+-]*\{[^}]*\}\s*)+/iu', '', $text) ?? $text;
    }

    /**
     * @return array<int, string>
     */
    public static function paragraphs(?string $html): array
    {
        $text = self::fromHtml($html);

        if ($text === null) {
            return [];
        }

        return collect(preg_split("/\n\s*\n/u", $text) ?: [])
            ->map(fn (string $paragraph): string => trim($paragraph))
            ->filter()
            ->values()
            ->all();
    }
}
