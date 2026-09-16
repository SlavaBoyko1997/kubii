<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next, string $locale): Response
    {
        abort_unless(in_array($locale, Locale::SUPPORTED, true), 404);

        app()->setLocale($locale);
        setlocale(LC_TIME, $locale === 'ru' ? 'ru_UA.UTF-8' : 'uk_UA.UTF-8');

        return $next($request);
    }
}
