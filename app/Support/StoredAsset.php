<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class StoredAsset
{
    public static function url(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $path = trim($path);

        if (str_starts_with($path, '[') || str_starts_with($path, '{')) {
            $decoded = json_decode($path, true);

            if (is_array($decoded)) {
                $path = trim((string) Arr::first(array_filter($decoded)));
            }
        }

        if (blank($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '//')) {
            return self::normalizeExternalUrl($path);
        }

        $path = preg_replace('#^public/#', '', $path) ?? $path;

        if (str_starts_with($path, '/storage/')) {
            return asset(ltrim($path, '/'));
        }

        if (str_starts_with($path, 'storage/')) {
            return asset($path);
        }

        if (str_starts_with($path, '/')) {
            return asset(ltrim($path, '/'));
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * @param  array<int, string|null>|null  $paths
     * @return array<int, string>
     */
    public static function urls(?array $paths): array
    {
        return collect($paths ?? [])
            ->map(fn (?string $path): ?string => self::url($path))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private static function normalizeExternalUrl(string $url): string
    {
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        $replacements = [
            'http://65.108.104.155/' => 'https://file.pobedov.com/',
            'http://file.pobedov.com/' => 'https://file.pobedov.com/',
        ];

        foreach ($replacements as $from => $to) {
            if (str_starts_with($url, $from)) {
                return $to.substr($url, strlen($from));
            }
        }

        return $url;
    }
}
