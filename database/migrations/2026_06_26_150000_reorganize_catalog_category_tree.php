<?php

use App\Models\Category;
use App\Support\CatalogCategoryMergeMap;
use App\Support\CatalogCache;
use App\Support\CatalogMergedCategoryPruner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<array{source: int, target: int}> */
    private array $changeLog = [];

    public function up(): void
    {
        if (! Schema::hasTable('category_slug_redirects')) {
            Schema::create('category_slug_redirects', function (Blueprint $table): void {
                $table->id();
                $table->string('old_path')->unique();
                $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
                $table->timestamps();
            });
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (DB::table('categories')->count() === 0) {
            return;
        }

        DB::transaction(function (): void {
            $this->applySpecialSplits();
            $this->backfillBrands();
            $this->applyMergeMap();
            $this->applyPromoMoves();
            $this->repointFeedTargets();
            $this->buildSlugRedirects();
            $this->restructureTree();
            $this->deactivateMergedSources();
            app(CatalogMergedCategoryPruner::class)->prune();
        });

        app(CatalogCache::class)->invalidateAndLog(
            \App\Models\CatalogCacheLog::TRIGGER_SYSTEM,
            'Реорганізація дерева категорій',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('category_slug_redirects');
    }

    private function applySpecialSplits(): void
    {
        if ($this->categoryExists(433)) {
            DB::update("
                UPDATE products
                SET category_id = 433
                WHERE category_id = 379
                  AND (
                    LOWER(name) LIKE '%гамак%'
                    OR LOWER(name) LIKE '%hammock%'
                    OR LOWER(COALESCE(name_ru, '')) LIKE '%гамак%'
                    OR LOWER(COALESCE(name_ru, '')) LIKE '%hammock%'
                  )
            ");
        }

        if ($this->categoryExists(434)) {
            DB::update('UPDATE products SET category_id = 434 WHERE category_id = 379');
        }

        if ($this->categoryExists(367)) {
            DB::update("
                UPDATE products
                SET category_id = 367
                WHERE category_id = 222
                  AND (
                    LOWER(name) LIKE '%літн%'
                    OR LOWER(name) LIKE '%litn%'
                    OR LOWER(name) LIKE '%summer%'
                    OR LOWER(COALESCE(name_ru, '')) LIKE '%летн%'
                    OR LOWER(COALESCE(name_ru, '')) LIKE '%summer%'
                  )
            ");
        }

        if ($this->categoryExists(368)) {
            DB::update("
                UPDATE products
                SET category_id = 368
                WHERE category_id = 222
                  AND (
                    LOWER(name) LIKE '%зим%'
                    OR LOWER(name) LIKE '%zym%'
                    OR LOWER(name) LIKE '%winter%'
                    OR LOWER(COALESCE(name_ru, '')) LIKE '%зим%'
                    OR LOWER(COALESCE(name_ru, '')) LIKE '%winter%'
                  )
            ");
        }

        if ($this->categoryExists(369)) {
            DB::update("
                UPDATE products
                SET category_id = 369
                WHERE category_id = 222
                  AND (
                    LOWER(name) LIKE '%всесез%'
                    OR LOWER(name) LIKE '%all season%'
                    OR LOWER(COALESCE(name_ru, '')) LIKE '%всесез%'
                  )
            ");
        }

        if ($this->categoryExists(12)) {
            DB::update('UPDATE products SET category_id = 12 WHERE category_id = 222');
        }
    }

    private function backfillBrands(): void
    {
        foreach (CatalogCategoryMergeMap::brandByCategoryId() as $categoryId => $brand) {
            DB::update(
                'UPDATE products SET brand = ? WHERE category_id = ? AND (brand IS NULL OR TRIM(brand) = \'\')',
                [$brand, $categoryId],
            );
        }
    }

    private function applyMergeMap(): void
    {
        foreach ($this->sourceTargetPairs() as $source => $target) {
            $moved = $this->moveProducts((int) $source, (int) $target);

            if ($moved > 0) {
                $this->changeLog[] = ['source' => $source, 'target' => $target, 'products' => $moved];
            }
        }
    }

    private function applyPromoMoves(): void
    {
        foreach ([[151, 448], [108, 490], [84, 467]] as [$from, $preferredTarget]) {
            $target = CatalogCategoryMergeMap::resolveTargetId(
                $preferredTarget,
                [$from],
                fn (int $id): bool => $this->categoryExists($id),
            );

            if ($target === null) {
                continue;
            }

            $this->moveProducts($from, $target);
        }
    }

    private function repointFeedTargets(): void
    {
        $pairs = $this->sourceTargetPairs();

        foreach ($pairs as $source => $target) {
            DB::update(
                'UPDATE categories SET target_category_id = ?, updated_at = CURRENT_TIMESTAMP WHERE target_category_id = ?',
                [$target, $source],
            );
        }
    }

    private function restructureTree(): void
    {
        $lightsRootId = DB::table('categories')->where('slug', 'lihtari-ta-elektrozyvlennia')->value('id');

        if (! $lightsRootId) {
            $lightsRootId = DB::table('categories')->insertGetId([
                'name' => 'Ліхтарі та електроживлення',
                'name_ru' => 'Фонари и электропитание',
                'slug' => 'lihtari-ta-elektrozyvlennia',
                'slug_ru' => 'lihtari-ta-elektrozyvlennia',
                'parent_id' => null,
                'sort_order' => 15,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('categories')->where('id', $lightsRootId)->update([
                'parent_id' => null,
                'is_active' => true,
                'updated_at' => now(),
            ]);
        }

        DB::table('categories')->where('id', 400)->update(['parent_id' => $lightsRootId, 'updated_at' => now()]);
        DB::table('categories')->where('id', 442)->update(['parent_id' => $lightsRootId, 'updated_at' => now()]);

        foreach ([412, 406, 478, 2, 10, 5] as $rootId) {
            DB::table('categories')->where('id', $rootId)->update(['parent_id' => null, 'updated_at' => now()]);
        }

        DB::table('categories')->where('id', 422)->update(['parent_id' => 5, 'updated_at' => now()]);

        DB::table('categories')->where('id', 415)->update(['parent_id' => 412, 'updated_at' => now()]);
    }

    private function deactivateMergedSources(): void
    {
        $pairs = $this->sourceTargetPairs();

        foreach ($pairs as $source => $target) {
            DB::table('categories')->where('id', $source)->update([
                'is_active' => false,
                'target_category_id' => $target,
                'updated_at' => now(),
            ]);
        }

        foreach (CatalogCategoryMergeMap::promoCategoryIds() as $categoryId) {
            DB::table('categories')->where('id', $categoryId)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
        }

        foreach (CatalogCategoryMergeMap::wrapperCategoryIds() as $categoryId) {
            if (DB::table('products')->where('category_id', $categoryId)->exists()) {
                continue;
            }

            DB::table('categories')->where('id', $categoryId)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
        }

        DB::table('categories')->whereIn('id', [7, 134, 86, 80])->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
    }

    private function buildSlugRedirects(): void
    {
        $pairs = $this->sourceTargetPairs();
        $categories = Category::withoutGlobalScopes()->get()->keyBy('id');
        $redirects = [];

        foreach ($pairs as $sourceId => $targetId) {
            $source = $categories->get($sourceId);
            $target = $categories->get($targetId);

            if (! $source || ! $target) {
                continue;
            }

            $path = mb_strtolower(trim($source->fullCatalogPath(), '/'));

            if ($path === '') {
                continue;
            }

            $redirects[$path] = $targetId;
        }

        foreach ($redirects as $path => $categoryId) {
            DB::table('category_slug_redirects')->updateOrInsert(
                ['old_path' => $path],
                ['category_id' => $categoryId, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    /**
     * @return array<int, int>
     */
    private function sourceTargetPairs(): array
    {
        return CatalogCategoryMergeMap::buildSourceTargetPairs(
            fn (int $id): bool => $this->categoryExists($id),
        );
    }

    private function categoryExists(int $id): bool
    {
        return DB::table('categories')->where('id', $id)->exists();
    }

    private function moveProducts(int $from, int $to): int
    {
        if ($from === $to || ! $this->categoryExists($from) || ! $this->categoryExists($to)) {
            return 0;
        }

        return DB::update(
            'UPDATE products SET category_id = ?, updated_at = CURRENT_TIMESTAMP WHERE category_id = ?',
            [$to, $from],
        );
    }
};
