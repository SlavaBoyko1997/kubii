<?php

namespace App\Filament\Pages;

use App\Models\CatalogCacheLog;
use App\Support\CatalogCacheWarmProgress;
use App\Support\CatalogCacheWorker;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class CatalogCache extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $navigationLabel = 'Кеш каталогу';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.catalog-cache';

    public array $status = [];

    /** @var \Illuminate\Support\Collection<int, CatalogCacheLog> */
    public $cacheLogs;

    public function mount(CatalogCacheWarmProgress $progress): void
    {
        $this->status = $progress->latest() ?? $this->emptyStatus();
        $this->loadCacheLogs();
    }

    public function startWarm(CatalogCacheWarmProgress $progress, CatalogCacheWorker $worker): void
    {
        if ($progress->isRunning()) {
            $this->status = $progress->latest() ?? $this->emptyStatus();
            Notification::make()
                ->warning()
                ->title('Оновлення кешу вже виконується')
                ->send();

            return;
        }

        $runId = (string) Str::uuid();
        $this->status = $progress->start($runId);

        try {
            $worker->start($runId);
        } catch (\Throwable $exception) {
            $this->status = $progress->fail($runId, $exception->getMessage());

            Notification::make()
                ->danger()
                ->title('Не вдалося запустити оновлення кешу')
                ->body($exception->getMessage())
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Оновлення кешу запущено')
            ->body('Стара версія залишатиметься активною до завершення прогріву.')
            ->send();
    }

    public function refreshProgress(CatalogCacheWarmProgress $progress): void
    {
        $this->status = $progress->latest() ?? $this->emptyStatus();
        $this->loadCacheLogs();
    }

    private function loadCacheLogs(): void
    {
        $this->cacheLogs = CatalogCacheLog::query()
            ->latest('finished_at')
            ->limit(30)
            ->get();
    }

    public function getTitle(): string
    {
        return 'Кеш каталогу';
    }

    private function emptyStatus(): array
    {
        return [
            'state' => 'idle',
            'stage' => 'Кеш ще не оновлювався з адмінки',
            'current' => 0,
            'total' => 0,
            'percent' => 0,
            'message' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }
}
