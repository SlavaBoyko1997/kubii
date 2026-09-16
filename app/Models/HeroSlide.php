<?php

namespace App\Models;

use App\Support\StoredAsset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class HeroSlide extends Model
{
    protected $fillable = [
        'title',
        'subtitle',
        'button_label',
        'button_url',
        'image_path',
        'image_url',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function imageUrl(): string
    {
        if (filled($this->image_path)) {
            return StoredAsset::url($this->image_path) ?: asset('images/hero-outdoor.webp');
        }

        if (filled($this->image_url)) {
            return StoredAsset::url($this->image_url) ?: $this->image_url;
        }

        return asset('images/hero-outdoor.webp');
    }
}
