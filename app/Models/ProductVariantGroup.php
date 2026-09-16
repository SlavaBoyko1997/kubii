<?php

namespace App\Models;

use App\Support\CatalogCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariantGroup extends Model
{
    public const FRONTEND_STATUSES = ['approved', 'published'];

    /**
     * Bulk discovery creates many groups; mute per-row cache invalidation
     * and let the caller invalidate once at the end.
     */
    public static bool $muteCatalogSideEffects = false;

    protected $fillable = [
        'category_id',
        'primary_product_id',
        'brand',
        'title',
        'group_key',
        'grouping_level',
        'confidence',
        'variant_option_keys',
        'secondary_spec_keys',
        'candidate_summary',
        'status',
        'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'variant_option_keys' => 'array',
            'secondary_spec_keys' => 'array',
            'candidate_summary' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $group): void {
            if (static::$muteCatalogSideEffects) {
                return;
            }

            $group->syncProductsCatalogVisibility();

            app(CatalogCache::class)->invalidate();
        });
        static::deleted(function (): void {
            if (static::$muteCatalogSideEffects) {
                return;
            }

            app(CatalogCache::class)->invalidate();
        });
    }

    public function syncProductsCatalogVisibility(): void
    {
        $products = Product::query()->where('variant_group_id', $this->id);

        if (! in_array($this->status, self::FRONTEND_STATUSES, true)) {
            $products->update(['is_visible_in_catalog' => true]);

            return;
        }

        $products->update([
            'is_visible_in_catalog' => false,
            'is_primary_variant' => false,
        ]);

        $primaryId = $this->primary_product_id
            ?: Product::query()
                ->where('variant_group_id', $this->id)
                ->where('is_active', true)
                ->orderByDesc('stock')
                ->orderBy('id')
                ->value('id');

        if (! $primaryId) {
            return;
        }

        Product::query()
            ->where('id', $primaryId)
            ->update([
                'is_visible_in_catalog' => true,
                'is_primary_variant' => true,
            ]);

        if ($this->primary_product_id !== $primaryId) {
            $this->updateQuietly(['primary_product_id' => $primaryId]);
        }
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function primaryProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'primary_product_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'variant_group_id');
    }

    public function isVisibleOnFrontend(): bool
    {
        return in_array($this->status, self::FRONTEND_STATUSES, true);
    }
}
