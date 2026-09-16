<?php

namespace App\Support;

use Illuminate\Support\Str;

class ProductBrand
{
    public static function slug(string $name): string
    {
        $slug = Str::slug($name, '-', 'uk') ?: Str::slug($name);

        return $slug !== '' ? $slug : 'brand';
    }
}
