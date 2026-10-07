<?php

namespace App\Support;

class GoogleAuth
{
    public static function enabled(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    public static function redirectUri(): string
    {
        $configured = trim((string) config('services.google.redirect', ''));

        if ($configured !== '') {
            return $configured;
        }

        $path = Locale::route('auth.google.callback', [], false, Locale::DEFAULT);

        return AppUrl::absoluteIfPossible($path);
    }
}
