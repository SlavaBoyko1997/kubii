<?php

namespace App\Services;

use App\Support\Locale;

class SeoCanonical
{
    public function make(string $url): array
    {
        $canonical = $this->absoluteUrl($url);
        $uk = $this->normalizeRoot(Locale::prefixPath($canonical, 'uk'));
        $ru = $this->normalizeRoot(Locale::prefixPath($canonical, 'ru'));

        return [
            'url' => Locale::current() === 'ru' ? $ru : $uk,
            'alternates' => [
                'uk' => $uk,
                'ru' => $ru,
                'x-default' => $uk,
            ],
        ];
    }

    private function absoluteUrl(string $url): string
    {
        $url = preg_replace('/#.*$/', '', trim($url)) ?: request()->url();

        return preg_match('#^https?://#i', $url) === 1
            ? $url
            : url('/'.ltrim($url, '/'));
    }

    private function normalizeRoot(string $url): string
    {
        $parts = parse_url($url);

        if (($parts['path'] ?? '') === '/' && ! isset($parts['query'])) {
            return rtrim($url, '/');
        }

        return $url;
    }
}
