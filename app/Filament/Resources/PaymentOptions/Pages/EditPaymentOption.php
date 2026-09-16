<?php

namespace App\Filament\Resources\PaymentOptions\Pages;

use App\Filament\Resources\PaymentOptions\PaymentOptionResource;
use Filament\Resources\Pages\EditRecord;

class EditPaymentOption extends EditRecord
{
    protected static string $resource = PaymentOptionResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['categories'] = $this->record->categories()
            ->pluck('categories.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->categories()->sync(
            collect($this->form->getState()['categories'] ?? [])
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->values()
                ->all(),
        );
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
