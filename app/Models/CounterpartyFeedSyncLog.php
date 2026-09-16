<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CounterpartyFeedSyncLog extends Model
{
    public const TRIGGER_AUTO = 'auto';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_CLI = 'cli';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'counterparty_id',
        'trigger',
        'status',
        'products_count',
        'categories_count',
        'variant_groups_created',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'products_count' => 'integer',
            'categories_count' => 'integer',
            'variant_groups_created' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function triggerLabel(): string
    {
        return match ($this->trigger) {
            self::TRIGGER_AUTO => 'Авто',
            self::TRIGGER_MANUAL => 'Вручну',
            self::TRIGGER_CLI => 'CLI',
            default => $this->trigger,
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_SUCCESS => 'Успішно',
            self::STATUS_FAILED => 'Помилка',
            default => $this->status,
        };
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }
}
