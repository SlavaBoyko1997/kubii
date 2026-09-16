<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

class AppUrl
{
    public static function configureFromRequest(): void
    {
        $origin = self::requestOrigin() ?? self::configuredOrigin();

        if ($origin === null) {
            return;
        }

        URL::forceRootUrl($origin);

        $scheme = parse_url($origin, PHP_URL_SCHEME);
        if ($scheme === 'https' || $scheme === 'http') {
            URL::forceScheme($scheme);
        }
    }

    public static function configuredOrigin(): ?string
    {
        $configured = rtrim((string) config('app.url'), '/');
        $host = parse_url($configured, PHP_URL_HOST);

        if (! is_string($host) || $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return null;
        }

        $scheme = parse_url($configured, PHP_URL_SCHEME) ?: 'https';

        return $scheme.'://'.$host;
    }

    public static function relativePath(?string $url): string
    {
        if ($url === null || $url === '') {
            return '';
        }

        if (str_starts_with($url, '//')) {
            return self::forBrowser('http:'.$url);
        }

        return self::forBrowser($url);
    }

    public static function requestOrigin(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        if ($request === null) {
            return null;
        }

        $host = trim((string) ($request->headers->get('X-Forwarded-Host') ?: $request->getHost()));
        $scheme = strtolower(trim(explode(',', (string) ($request->headers->get('X-Forwarded-Proto') ?: $request->getScheme()))[0]));

        $configured = self::configuredOrigin();

        if ($configured !== null && str_starts_with($configured, 'https://')) {
            $scheme = 'https';
        }

        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return null;
        }

        return $scheme.'://'.$host;
    }

    public static function absoluteIfPossible(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return self::forBrowser($path);
        }

        $origin = self::requestOrigin();

        if ($origin !== null) {
            return $origin.($path !== '' && $path[0] === '/' ? '' : '/').$path;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return self::forBrowser(url($path));
    }

    public static function forBrowser(?string $url): string
    {
        if ($url === null || $url === '') {
            return '';
        }

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return $url;
        }

        $parts = parse_url($url);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $path.$query.$fragment;
    }

    public static function sanitizeHtmlUrls(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        foreach (self::stripHostPatterns(includeConfiguredHost: true) as $pattern) {
            $html = preg_replace($pattern, '', $html) ?? $html;
        }

        return $html;
    }

    public static function sanitizeLocalhostHtmlUrls(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        foreach (self::stripHostPatterns(includeConfiguredHost: false) as $pattern) {
            $html = preg_replace($pattern, '', $html) ?? $html;
        }

        return $html;
    }

    /**
     * @return list<string>
     */
    private static function stripHostPatterns(bool $includeConfiguredHost): array
    {
        $patterns = [
            '#https?://(?:localhost|127\.0\.0\.1)(?::\d+)?#i',
            '#//(?:localhost|127\.0\.0\.1)(?::\d+)?#i',
        ];

        if (! $includeConfiguredHost) {
            return $patterns;
        }

        $configuredHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (
            is_string($configuredHost)
            && $configuredHost !== ''
            && ! in_array($configuredHost, ['localhost', '127.0.0.1'], true)
        ) {
            $patterns[] = '#https?://'.preg_quote($configuredHost, '#').'(?::\d+)?#i';
        }

        return $patterns;
    }
}
