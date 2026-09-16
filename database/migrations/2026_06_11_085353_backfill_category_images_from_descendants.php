<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $categories = DB::table('categories')
            ->select(['id', 'parent_id', 'image_url', 'image_path'])
            ->orderByDesc('id')
            ->get();
        $resolvedImages = $categories
            ->filter(fn ($category): bool => filled($category->image_path) || filled($category->image_url))
            ->mapWithKeys(fn ($category): array => [
                $category->id => [
                    'image_path' => $category->image_path,
                    'image_url' => $category->image_url,
                ],
            ])
            ->all();
        $children = $categories->groupBy('parent_id');

        do {
            $resolvedThisPass = 0;

            foreach ($categories as $category) {
                if (isset($resolvedImages[$category->id])) {
                    continue;
                }

                $childImage = $children->get($category->id, collect())
                    ->map(fn ($child) => $resolvedImages[$child->id] ?? null)
                    ->filter()
                    ->first();

                if (! $childImage) {
                    continue;
                }

                DB::table('categories')->where('id', $category->id)->update($childImage);
                $resolvedImages[$category->id] = $childImage;
                $resolvedThisPass++;
            }
        } while ($resolvedThisPass > 0);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Category images are intentionally retained on rollback.
    }
};
