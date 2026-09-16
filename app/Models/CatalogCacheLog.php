<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogCacheLog extends Model
{
    public const ACTION_WARM = 'warm';

    public const ACTION_CLEAR = 'clear';

    public const TRIGGER_AUTO = 'auto';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_CLI = 'cli';

    public const TRIGGER_SYSTEM = 'system';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'action',
        'trigger',
        'status',
        'reason',
        'fresh',
        'steps_completed',
        'steps_total',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'fresh' => 'boolean',
            'steps_completed' => 'integer',
            'steps_total' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_WARM => 'Прогрів',
            self::ACTION_CLEAR => 'Очищення',
            default => $this->action,
        };
    }

    public function triggerLabel(): string
    {
        return match ($this->trigger) {
            self::TRIGGER_AUTO => 'Авто',
            self::TRIGGER_MANUAL => 'Вручну',
            self::TRIGGER_CLI => 'CLI',
            self::TRIGGER_SYSTEM => 'Система',
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
