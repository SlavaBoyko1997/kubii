<?php

namespace App\Filament\Resources\Counterparties\Pages;

use App\Filament\Resources\Counterparties\CounterpartyResource;
use App\Filament\Resources\Products\ProductResource;
use App\Jobs\MirrorProductImagesJob;
use App\Models\Counterparty;
use App\Services\ProductImageMirror;
use App\Support\CounterpartyFeedImportProgress;
use App\Support\CounterpartyFeedWorker;
use App\Support\CounterpartyImageMirrorProgress;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class EditCounterparty extends EditRecord
{
    protected static string $resource = CounterpartyResource::class;

    /** @var array<string, mixed> */
    public array $feedImportStatus = [];

    /** @var array<string, mixed> */
    public array $imageMirrorStatus = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->refreshFeedImportProgress();
    }

    public function refreshFeedImportProgress(): void
    {
        /** @var Counterparty $counterparty */
        $counterparty = $this->getRecord();
        $progress = app(CounterpartyFeedImportProgress::class);

        $progress->failStaleQueued($counterparty->getKey());

        $imageProgress = app(CounterpartyImageMirrorProgress::class);
        $imageProgress->recoverIfStuck($counterparty->getKey(), idleSeconds: 45);
        $imageProgress->recoverIfMainFinished($counterparty->getKey());
        $imageProgress->failStale($counterparty->getKey());

        $this->feedImportStatus = $progress->status($counterparty->getKey())
            ?? $this->emptyFeedImportStatus();

        $this->imageMirrorStatus = app(CounterpartyImageMirrorProgress::class)->status($counterparty->getKey())
            ?? $this->emptyImageMirrorStatus();

        if (in_array($this->feedImportStatus['state'] ?? null, ['completed', 'failed'], true)) {
            $this->record->refresh();
            $this->fillForm();
        }

        $this->dispatch('$refresh');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('filament.resources.counterparties.feed-import-progress'),
                View::make('filament.resources.counterparties.image-mirror-progress'),
                View::make('filament.resources.counterparties.feed-sync-log')
                    ->viewData(fn (): array => [
                        'feedSyncLogs' => $this->getRecord()->feedSyncLogs()->limit(30)->get(),
                    ]),
                $this->getFormContentComponent(),
                $this->getRelationManagersContentComponent(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewProducts')
                ->label('Товари контрагента')
                ->icon(Heroicon::OutlinedShoppingBag)
                ->url(fn (): string => ProductResource::getUrl('index', [
                    'tableFilters' => [
                        'counterparty' => [
                            'value' => $this->getRecord()->getKey(),
                        ],
                    ],
                ]))
                ->visible(fn (): bool => $this->getRecord()->products()->exists()),
            Action::make('processFeed')
                ->label('Обробити фід')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Обробити фід')
                ->modalDescription('Завантажити фід і оновити товари, категорії, ціни та залишки. Фото при цьому не завантажуються — для них є окрема кнопка.')
                ->visible(fn (): bool => filled($this->record->feed_url))
                ->disabled(fn (): bool => $this->isBackgroundTaskRunning())
                ->action(function (): void {
                    $this->form->validate();
                    $this->record->update($this->form->getState());

                    if ($this->isBackgroundTaskRunning()) {
                        Notification::make()
                            ->warning()
                            ->title('Імпорт уже виконується')
                            ->body('Зачекайте завершення поточного імпорту.')
                            ->send();

                        return;
                    }

                    app(CounterpartyFeedImportProgress::class)->start($this->record->getKey());

                    try {
                        app(CounterpartyFeedWorker::class)->start(
                            (int) $this->record->getKey(),
                            (string) $this->record->slug,
                        );
                    } catch (\Throwable $exception) {
                        app(CounterpartyFeedImportProgress::class)->fail(
                            (int) $this->record->getKey(),
                            $exception->getMessage(),
                        );

                        Notification::make()
                            ->danger()
                            ->title('Не вдалося запустити імпорт')
                            ->body($exception->getMessage())
                            ->send();

                        $this->refreshFeedImportProgress();

                        return;
                    }

                    $this->refreshFeedImportProgress();

                    Notification::make()
                        ->success()
                        ->title('Імпорт запущено')
                        ->body('Слідкуйте за прогресом у блоці «Обробка фіду».')
                        ->send();
                }),
            Action::make('processImages')
                ->label('Імпортувати фото')
                ->icon(Heroicon::OutlinedPhoto)
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Імпортувати фото')
                ->modalDescription('Завантажити відсутні головні фото товарів, а після них — зображення галереї. Дані фіду повторно імпортуватися не будуть.')
                ->visible(fn (): bool => $this->record->products()->exists())
                ->disabled(fn (): bool => $this->isBackgroundTaskRunning())
                ->action(function (): void {
                    if ($this->isBackgroundTaskRunning()) {
                        Notification::make()
                            ->warning()
                            ->title('Фонове завдання вже виконується')
                            ->body('Зачекайте завершення поточного імпорту.')
                            ->send();

                        return;
                    }

                    $counterpartyId = (int) $this->record->getKey();
                    $mirror = app(ProductImageMirror::class);
                    $progress = app(CounterpartyImageMirrorProgress::class);
                    $mainProductIds = $mirror->pendingMainProductIds($counterpartyId);

                    if ($mainProductIds !== []) {
                        $progress->start($counterpartyId, count($mainProductIds));
                        MirrorProductImagesJob::dispatch(counterpartyId: $counterpartyId);
                    } else {
                        $galleryTotal = $mirror->estimatePendingGalleryImageCount($counterpartyId);

                        if ($galleryTotal < 1) {
                            Notification::make()
                                ->success()
                                ->title('Усі фото вже завантажені')
                                ->send();

                            return;
                        }

                        $progress->startPhase($counterpartyId, ProductImageMirror::PHASE_GALLERY, $galleryTotal);
                        MirrorProductImagesJob::dispatch(
                            counterpartyId: $counterpartyId,
                            phase: ProductImageMirror::PHASE_GALLERY,
                        );
                    }

                    $this->refreshFeedImportProgress();

                    Notification::make()
                        ->success()
                        ->title('Імпорт фото запущено')
                        ->body('Спочатку завантажаться головні фото, потім галерея.')
                        ->send();
                }),
            Action::make('stopImages')
                ->label('Зупинити фото')
                ->icon(Heroicon::OutlinedStopCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Зупинити імпорт фото?')
                ->modalDescription('Поточне завантаження зупиниться. Уже збережені фото залишаться, а наступний запуск продовжить з решти.')
                ->visible(fn (): bool => (
                    app(CounterpartyImageMirrorProgress::class)->status($this->record->getKey())['state'] ?? null
                ) === 'running')
                ->action(function (): void {
                    app(CounterpartyImageMirrorProgress::class)->cancel((int) $this->record->getKey());
                    $this->refreshFeedImportProgress();

                    Notification::make()
                        ->warning()
                        ->title('Імпорт фото зупинено')
                        ->body('Уже завантажені фото збережено. Можна запустити імпорт повторно пізніше.')
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }

    private function isFeedImportRunning(): bool
    {
        /** @var Counterparty $record */
        $record = $this->getRecord();

        return app(CounterpartyFeedImportProgress::class)->isRunning($record->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyFeedImportStatus(): array
    {
        return [
            'state' => 'idle',
            'stage' => 'Імпорт ще не запускався',
            'current' => 0,
            'total' => 0,
            'percent' => 0,
            'products_imported' => 0,
            'categories_count' => null,
            'variant_groups_created' => null,
            'message' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyImageMirrorStatus(): array
    {
        return [
            'state' => 'idle',
            'stage' => 'Завантаження фото ще не запускалось',
            'current' => 0,
            'total' => 0,
            'percent' => 0,
            'mirrored' => 0,
            'failed' => 0,
            'processed' => 0,
            'message' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function isBackgroundTaskRunning(): bool
    {
        /** @var Counterparty $record */
        $record = $this->getRecord();

        return $this->isFeedImportRunning()
            || app(CounterpartyImageMirrorProgress::class)->isRunning($record->getKey());
    }
}
