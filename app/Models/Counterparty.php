<?php

namespace App\Models;

use App\Models\CounterpartyFeedSyncLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Counterparty extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'feed_url',
        'feed_format',
        'feed_profile',
        'is_active',
        'auto_sync',
        'last_synced_at',
        'last_sync_products_count',
        'last_sync_error',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'auto_sync' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_sync_products_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Counterparty $counterparty): void {
            if (blank($counterparty->slug)) {
                $counterparty->slug = static::uniqueSlug((string) $counterparty->name);
            }
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function feedSyncLogs(): HasMany
    {
        return $this->hasMany(CounterpartyFeedSyncLog::class)->latest('finished_at');
    }

    public function latestFeedSyncLog(): HasOne
    {
        return $this->hasOne(CounterpartyFeedSyncLog::class)->latestOfMany('finished_at');
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'counterparty';
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
