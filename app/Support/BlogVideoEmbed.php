<?php

namespace App\Support;

class BlogVideoEmbed
{
    /**
     * @var list<string>
     */
    private const ALLOWED_IFRAME_HOSTS = [
        'www.youtube.com',
        'youtube.com',
        'www.youtube-nocookie.com',
        'youtube-nocookie.com',
        'player.vimeo.com',
        'vimeo.com',
    ];

    public static function embedHtml(?string $url): ?string
    {
        $embedUrl = self::resolveEmbedUrl($url);

        if ($embedUrl === null) {
            return null;
        }

        $safeUrl = e($embedUrl);

        return <<<HTML
<div class="blog-video-embed">
<iframe src="{$safeUrl}" title="Відео" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
</div>
HTML;
    }

    public static function isAllowedEmbedUrl(?string $url): bool
    {
        if (blank($url)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);

        if (! in_array($host, self::ALLOWED_IFRAME_HOSTS, true)) {
            return false;
        }

        return self::resolveEmbedUrl($url) !== null;
    }

    public static function resolveEmbedUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $url = trim($url);

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        if (in_array($host, ['www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'youtube-nocookie.com'], true)) {
            if (str_starts_with($path, '/embed/')) {
                $videoId = trim(substr($path, strlen('/embed/')), '/');
            } elseif ($path === '/watch' && filled($query['v'] ?? null)) {
                $videoId = (string) $query['v'];
            } else {
                return null;
            }

            if (! preg_match('/^[\w-]{11}$/', $videoId)) {
                return null;
            }

            return 'https://www.youtube-nocookie.com/embed/'.$videoId;
        }

        if ($host === 'youtu.be') {
            $videoId = trim($path, '/');

            if (! preg_match('/^[\w-]{11}$/', $videoId)) {
                return null;
            }

            return 'https://www.youtube-nocookie.com/embed/'.$videoId;
        }

        if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true)) {
            if (preg_match('#/(?:video/)?(\d+)#', $path, $matches)) {
                return 'https://player.vimeo.com/video/'.$matches[1];
            }
        }

        return null;
    }
}
