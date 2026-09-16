<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Products\ProductResource;
use App\Services\CatalogSpecificationFacets;
use App\Support\CategorySpecFilterSynchronizer;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['visible_filters'] ??= ['brand', 'model', 'price'];

        if (($data['visible_spec_filters'] ?? null) === null) {
            $data['visible_spec_filters'] = array_keys(
                app(CatalogSpecificationFacets::class)->availableFilterOptions($this->getRecord(), includeInactive: true)
            );
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['visible_filters'] ?? []) === []) {
            $data['visible_filters'] = ['brand', 'model', 'price'];
        }

        if (($data['visible_spec_filters'] ?? null) === []) {
            $data['visible_spec_filters'] = null;
        }

        if (($data['visible_spec_filters_ru'] ?? null) === []) {
            $data['visible_spec_filters_ru'] = null;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncSpecFilters')
                ->label('Оновити фільтри з товарів')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->action(function (): void {
                    $updated = app(CategorySpecFilterSynchronizer::class)->syncCategory($this->getRecord());

                    \Filament\Notifications\Notification::make()
                        ->title($updated ? 'Фільтри оновлено' : 'Немає нових фільтрів')
                        ->body($updated
                            ? 'Доступні характеристики товарів додано до списку фільтрів категорії.'
                            : 'Усі характеристики вже є у списку фільтрів.')
                        ->success()
                        ->send();

                    $this->fillForm();
                }),
            Action::make('createProduct')
                ->label('Додати товар')
                ->icon(Heroicon::OutlinedPlus)
                ->url(fn (): string => ProductResource::getUrl('create', [
                    'category_id' => $this->getRecord()->getKey(),
                ])),
            DeleteAction::make(),
        ];
    }
}
