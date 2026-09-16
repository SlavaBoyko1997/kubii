<?php

namespace App\Support;

class MetaPixel
{
    public static function enabled(): bool
    {
        if (! config('services.meta_pixel.enabled', true)) {
            return false;
        }

        return self::pixelId() !== null;
    }

    public static function pixelId(): ?string
    {
        $id = trim((string) config('services.meta_pixel.pixel_id', ''));

        return $id !== '' ? $id : null;
    }
}
