<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Route;

class Locale
{
    public const DEFAULT = 'uk';

    public const SUPPORTED = ['uk', 'ru'];

    public static function current(): string
    {
        $locale = app()->getLocale();

        return in_array($locale, self::SUPPORTED, true) ? $locale : self::DEFAULT;
    }

    public static function isRussian(): bool
    {
        return self::current() === 'ru';
    }

    public static function routeName(string $name, ?string $locale = null): string
    {
        $locale ??= self::current();
        $localized = $locale === self::DEFAULT ? $name : $name.'.'.$locale;

        return Route::has($localized) ? $localized : $name;
    }

    public static function route(string $name, mixed $parameters = [], bool $absolute = true, ?string $locale = null): string
    {
        return route(self::routeName($name, $locale), $parameters, $absolute);
    }

    public static function prefixPath(string $path, ?string $locale = null): string
    {
        $locale ??= self::current();
        $parts = parse_url($path);
        $cleanPath = '/'.ltrim((string) ($parts['path'] ?? ''), '/');
        $cleanPath = preg_replace('#^/(?:uk|ru)(?=/|$)#', '', $cleanPath) ?: '/';

        if ($locale !== self::DEFAULT) {
            $cleanPath = '/'.$locale.($cleanPath === '/' ? '' : $cleanPath);
        }

        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        if (isset($parts['scheme'], $parts['host'])) {
            $port = isset($parts['port']) ? ':'.$parts['port'] : '';

            return $parts['scheme'].'://'.$parts['host'].$port.$cleanPath.$query.$fragment;
        }

        return $cleanPath.$query.$fragment;
    }

    public static function currentUrl(string $locale): string
    {
        return self::prefixPath(request()->fullUrl(), $locale);
    }

    public static function switchUrl(string $locale): string
    {
        $route = request()->route();
        $routeName = $route?->getName();

        if (! $route || ! $routeName) {
            return self::currentUrl($locale);
        }

        $routeName = preg_replace('/\.ru$/', '', $routeName);
        $product = $route->parameter('product');

        if ($product instanceof Product) {
            return self::appendQuery($product->url(locale: $locale));
        }

        if ($routeName === 'categories.path') {
            $currentPath = trim((string) $route->parameter('categoryPath'), '/');
            $category = Category::query()
                ->where('is_active', true)
                ->with('parentRecursive')
                ->get()
                ->first(fn (Category $category): bool => in_array($currentPath, [
                    trim($category->catalogPathFor(self::current()), '/'),
                    trim($category->fullCatalogPathFor(self::current()), '/'),
                ], true));

            if ($category) {
                return self::appendQuery($category->catalogUrl(locale: $locale));
            }

            return self::currentUrl($locale);
        }

        $parameters = collect($route->parameters())
            ->reject(fn ($value, string $key): bool => $key === 'categoryPath')
            ->all();

        return self::appendQuery(self::route($routeName, $parameters, true, $locale));
    }

    private static function appendQuery(string $url): string
    {
        $query = request()->getQueryString();

        return $query ? $url.(str_contains($url, '?') ? '&' : '?').$query : $url;
    }
}
