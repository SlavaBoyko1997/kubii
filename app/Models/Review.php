<?php

namespace App\Models;

use App\Support\CatalogCache;
use App\Support\ProductReviewStats;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Review extends Model
{
    protected $fillable = [
        'product_id',
        'product_variant_id',
        'user_id',
        'parent_id',
        'rating',
        'title',
        'body',
        'pros',
        'cons',
        'is_verified_purchase',
        'is_visible',
        'is_demo',
        'is_ai_generated',
        'source',
        'environment',
        'generation_batch_id',
        'model',
        'prompt_version',
        'input_hash',
        'metadata',
        'review_date',
        'generated_at',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'is_verified_purchase' => 'boolean',
            'is_demo' => 'boolean',
            'is_ai_generated' => 'boolean',
            'metadata' => 'array',
            'review_date' => 'datetime',
            'generated_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_variant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->where('is_visible', true)->oldest();
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ReviewVote::class);
    }

    protected static function booted(): void
    {
        static::saved(function (self $review): void {
            if ($review->parent_id === null) {
                ProductReviewStats::syncForProduct((int) $review->product_id);
                app(CatalogCache::class)->invalidate();
            }
        });

        static::deleted(function (self $review): void {
            if ($review->parent_id === null) {
                ProductReviewStats::syncForProduct((int) $review->product_id);
                app(CatalogCache::class)->invalidate();
            }
        });
    }
}
