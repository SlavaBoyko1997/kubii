<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $usedSlugs = DB::table('products')
            ->pluck('slug')
            ->filter()
            ->flip()
            ->map(fn (): bool => true)
            ->all();

        $products = DB::table('products')
            ->whereNotNull('external_id')
            ->when(
                DB::getDriverName() === 'sqlite',
                fn ($query) => $query->whereRaw('instr(slug, external_id) > 0'),
                fn ($query) => $query->whereRaw('LOCATE(external_id, slug) > 0'),
            )
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'external_id']);

        $products->each(function ($product) use (&$usedSlugs): void {
            unset($usedSlugs[$product->slug]);

            $cleanName = preg_replace(
                '/(?<!\d)'.preg_quote((string) $product->external_id, '/').'(?!\d)/u',
                ' ',
                $product->name,
            ) ?: $product->name;
            $base = Str::limit(Str::slug($cleanName) ?: 'tovar', 220, '');
            $slug = $base;
            $suffix = 2;

            while (isset($usedSlugs[$slug])) {
                $slug = Str::limit($base, 220 - strlen((string) $suffix), '').'-'.$suffix;
                $suffix++;
            }

            $usedSlugs[$slug] = true;
            DB::table('product_slug_redirects')->insertOrIgnore([
                'old_slug' => $product->slug,
                'product_id' => $product->id,
            ]);
            DB::table('products')->where('id', $product->id)->update([
                'slug' => $slug,
                'slug_ru' => $slug,
            ]);
        });
    }

    public function down(): void
    {
        //
    }
};
