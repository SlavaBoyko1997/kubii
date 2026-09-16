<?php

use App\Support\AppUrl;
use App\Support\Locale;

if (! function_exists('localized_route')) {
    function localized_route(string $name, mixed $parameters = [], bool $absolute = true, ?string $locale = null): string
    {
        return Locale::route($name, $parameters, $absolute, $locale);
    }
}

if (! function_exists('localized_url')) {
    function localized_url(string $path, ?string $locale = null): string
    {
        return Locale::prefixPath(url($path), $locale);
    }
}

if (! function_exists('browser_url')) {
    function browser_url(?string $url): string
    {
        return AppUrl::relativePath($url);
    }
}
