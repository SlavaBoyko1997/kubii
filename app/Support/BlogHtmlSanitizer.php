<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class BlogHtmlSanitizer
{
    public static function sanitize(string $html): string
    {
        $sanitized = (new HtmlSanitizer(self::config()))->sanitize($html);

        return self::filterUntrustedIframes($sanitized);
    }

    private static function config(): HtmlSanitizerConfig
    {
        return (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowAttribute('class', allowedElements: '*')
            ->allowAttribute('data-color', allowedElements: '*')
            ->allowAttribute('data-cols', allowedElements: '*')
            ->allowAttribute('data-col-span', allowedElements: '*')
            ->allowAttribute('data-from-breakpoint', allowedElements: '*')
            ->allowAttribute('data-id', allowedElements: '*')
            ->allowAttribute('data-type', allowedElements: '*')
            ->allowAttribute('style', allowedElements: '*')
            ->allowAttribute('width', allowedElements: 'img')
            ->allowAttribute('height', allowedElements: 'img')
            ->allowElement('iframe', ['src', 'title', 'allow', 'allowfullscreen', 'loading', 'class', 'width', 'height', 'frameborder', 'referrerpolicy'])
            ->withMaxInputLength(500_000);
    }

    private static function filterUntrustedIframes(string $html): string
    {
        return preg_replace_callback('/<iframe\b[^>]*\/?>/i', function (array $match): string {
            if (! preg_match('/\ssrc=(["\'])(.*?)\1/i', $match[0], $srcMatch)) {
                return '';
            }

            return BlogVideoEmbed::isAllowedEmbedUrl(html_entity_decode($srcMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                ? $match[0]
                : '';
        }, $html) ?? $html;
    }
}
