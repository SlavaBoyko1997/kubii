<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use App\Support\CategoryTreeBuilder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Throwable;

class CategoryTree extends Page
{
    protected static string $resource = CategoryResource::class;

    protected string $view = 'filament.resources.categories.pages.category-tree';

    /** @var list<int> */
    public array $openCategoryIds = [];

    public ?int $focusedCategoryId = null;

    public bool $showTrash = false;

    public function mount(): void
    {
        if ($this->showTrash) {
            return;
        }

        $this->openCategoryIds = collect(app(CategoryTreeBuilder::class)->nested())
            ->map(fn (array $node): int => $node['category']->id)
            ->all();
    }

    public function getTitle(): string
    {
        return $this->showTrash ? 'Смітник категорій' : 'Дерево категорій';
    }

    public function getSubheading(): ?string
    {
        if ($this->showTrash) {
            return 'Відновлення повертає категорію разом із усіма підкатегоріями, які були видалені разом із нею.';
        }

        return 'Стрілки ↑↓ — порядок на одному рівні. ←→ — винести на рівень вище або зробити підкатегорією попередньої.';
    }

    public function toggleCategory(int $categoryId): void
    {
        if (in_array($categoryId, $this->openCategoryIds, true)) {
            $this->openCategoryIds = array_values(array_diff($this->openCategoryIds, [$categoryId]));
        } else {
            $this->openCategoryIds[] = $categoryId;
        }
    }

    public function moveCategory(int $categoryId, ?int $parentId = null): void
    {
        $this->runCategoryTreeAction(
            fn () => app(CategoryTreeBuilder::class)->moveCategory($categoryId, $parentId),
            $categoryId,
        );
    }

    public function reorderCategory(int $categoryId, int $anchorCategoryId, string $position): void
    {
        $this->runCategoryTreeAction(
            fn () => app(CategoryTreeBuilder::class)->reorderCategory($categoryId, $anchorCategoryId, $position),
            $categoryId,
        );
    }

    public function shiftCategory(int $categoryId, string $direction): void
    {
        $this->runCategoryTreeAction(
            fn () => app(CategoryTreeBuilder::class)->shiftCategory($categoryId, $direction),
            $categoryId,
        );
    }

    public function indentCategory(int $categoryId): void
    {
        $this->runCategoryTreeAction(
            fn () => app(CategoryTreeBuilder::class)->indentCategory($categoryId),
            $categoryId,
        );
    }

    public function outdentCategory(int $categoryId): void
    {
        $this->runCategoryTreeAction(
            fn () => app(CategoryTreeBuilder::class)->outdentCategory($categoryId),
            $categoryId,
        );
    }

    public function toggleTrashView(): void
    {
        $this->showTrash = ! $this->showTrash;
        $this->focusedCategoryId = null;
        $this->openCategoryIds = [];
    }

    public function trashCategory(int $categoryId): void
    {
        $builder = app(CategoryTreeBuilder::class);

        try {
            $count = $builder->trashCategory($categoryId);

            Notification::make()
                ->success()
                ->title('Переміщено в смітник')
                ->body('У смітнику '.number_format($count).' категорій.')
                ->send();
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->warning()
                ->title('Не вдалося перемістити')
                ->body($exception->getMessage())
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Не вдалося перемістити')
                ->body($exception->getMessage())
                ->send();
        }
    }

    public function restoreCategory(int $categoryId): void
    {
        $builder = app(CategoryTreeBuilder::class);

        try {
            $count = $builder->restoreCategory($categoryId);

            Notification::make()
                ->success()
                ->title('Категорію відновлено')
                ->body('Повернуто '.number_format($count).' категорій.')
                ->send();

            $this->keepCategoryVisible($categoryId);
            $this->dispatch('category-tree-focus', categoryId: $categoryId);
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->warning()
                ->title('Не вдалося відновити')
                ->body($exception->getMessage())
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Не вдалося відновити')
                ->body($exception->getMessage())
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        $builder = app(CategoryTreeBuilder::class);
        $trashedCount = $builder->trashedCount();

        return [
            Action::make('toggleTrash')
                ->label($this->showTrash ? 'До дерева' : 'Смітник')
                ->icon($this->showTrash ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedTrash)
                ->color($this->showTrash ? 'gray' : 'danger')
                ->badge($trashedCount > 0 ? (string) $trashedCount : null)
                ->badgeColor('danger')
                ->action(fn () => $this->toggleTrashView()),
            Action::make('list')
                ->label('Таблиця')
                ->icon(Heroicon::OutlinedTableCells)
                ->url(CategoryResource::getUrl('index')),
            CreateAction::make()
                ->label('Нова категорія')
                ->visible(fn (): bool => ! $this->showTrash),
        ];
    }

    protected function getViewData(): array
    {
        $builder = app(CategoryTreeBuilder::class);

        return [
            'categoryTree' => $this->showTrash ? $builder->nestedTrashed() : $builder->nested(),
            'createUrl' => CategoryResource::getUrl('create'),
            'livewireId' => $this->getId(),
            'showTrash' => $this->showTrash,
        ];
    }

    private function runCategoryTreeAction(callable $action, int $categoryId): void
    {
        try {
            $action();
            $this->keepCategoryVisible($categoryId);
            $this->dispatch('category-tree-focus', categoryId: $categoryId);
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->warning()
                ->title('Не вдалося перемістити')
                ->body($exception->getMessage())
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Не вдалося перемістити')
                ->body($exception->getMessage())
                ->send();
        }
    }

    private function keepCategoryVisible(int $categoryId): void
    {
        $category = Category::query()
            ->with('parentRecursive')
            ->find($categoryId);

        if ($category === null) {
            return;
        }

        for ($current = $category; $current; $current = $current->parentRecursive) {
            if (! in_array($current->id, $this->openCategoryIds, true)) {
                $this->openCategoryIds[] = $current->id;
            }
        }

        $this->focusedCategoryId = $categoryId;
    }
}
