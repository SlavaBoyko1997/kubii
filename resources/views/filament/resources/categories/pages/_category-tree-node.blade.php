@php
    $category = $node['category'];
    $hasChildren = $node['children'] !== [];
    $baseFiltersCount = is_array($category->visible_filters) ? count($category->visible_filters) : 3;
    $specFiltersText = is_array($category->visible_spec_filters)
        ? count($category->visible_spec_filters).' характеристик'
        : 'автоматичні характеристики';
    $createChildUrl = \App\Filament\Resources\Categories\CategoryResource::getUrl('create', [
        'parent_id' => $category->id,
    ]);
    $createProductUrl = \App\Filament\Resources\Products\ProductResource::getUrl('create', [
        'category_id' => $category->id,
    ]);
    $canMoveUp = ! ($isFirst ?? false);
    $canMoveDown = ! ($isLast ?? false);
    $canOutdent = $category->parent_id !== null;
    $canIndent = $canMoveUp;
    $isOpen = in_array($category->id, $openCategoryIds ?? [], true);
    $isFocused = ($focusedCategoryId ?? null) === $category->id;
    $trashMode = (bool) ($showTrash ?? false);
@endphp

<div
    class="category-tree-node {{ $isOpen ? 'is-open' : '' }}"
    id="category-tree-node-{{ $category->id }}"
>
    <div
        class="category-tree-summary {{ $isFocused ? 'is-focused' : '' }} {{ $trashMode ? 'is-trash-mode' : '' }}"
        @unless($trashMode)
            data-drop-category-id="{{ $category->id }}"
        @endunless
    >
        @unless($trashMode)
            <span
                class="category-tree-drag-handle"
                data-category-id="{{ $category->id }}"
                title="Перетягнути категорію"
            >
                ⠿
            </span>
        @endunless

        <button
            type="button"
            class="category-tree-toggle {{ $hasChildren ? '' : 'is-empty' }}"
            wire:click="toggleCategory({{ $category->id }})"
            @disabled(! $hasChildren)
            aria-label="Розгорнути або згорнути"
        >
            {{ $hasChildren ? '›' : '•' }}
        </button>

        @unless($trashMode)
            <span class="category-tree-sort" role="group" aria-label="Порядок і рівень категорії">
                <button
                    type="button"
                    class="category-tree-sort-btn"
                    wire:click.stop="shiftCategory({{ $category->id }}, 'up')"
                    wire:loading.attr="disabled"
                    wire:target="shiftCategory,indentCategory,outdentCategory,moveCategory,reorderCategory"
                    @disabled(! $canMoveUp)
                    title="Підняти вище"
                    aria-label="Підняти вище"
                >↑</button>
                <button
                    type="button"
                    class="category-tree-sort-btn"
                    wire:click.stop="shiftCategory({{ $category->id }}, 'down')"
                    wire:loading.attr="disabled"
                    wire:target="shiftCategory,indentCategory,outdentCategory,moveCategory,reorderCategory"
                    @disabled(! $canMoveDown)
                    title="Опустити нижче"
                    aria-label="Опустити нижче"
                >↓</button>
                <button
                    type="button"
                    class="category-tree-sort-btn"
                    wire:click.stop="outdentCategory({{ $category->id }})"
                    wire:loading.attr="disabled"
                    wire:target="shiftCategory,indentCategory,outdentCategory,moveCategory,reorderCategory"
                    @disabled(! $canOutdent)
                    title="Винести на рівень вище"
                    aria-label="Винести на рівень вище"
                >←</button>
                <button
                    type="button"
                    class="category-tree-sort-btn"
                    wire:click.stop="indentCategory({{ $category->id }})"
                    wire:loading.attr="disabled"
                    wire:target="shiftCategory,indentCategory,outdentCategory,moveCategory,reorderCategory"
                    @disabled(! $canIndent)
                    title="Зробити підкатегорією попередньої"
                    aria-label="Зробити підкатегорією попередньої"
                >→</button>
            </span>
        @endunless

        <span class="category-tree-name">
            <strong>{{ $category->name }}</strong>
            <small>
                {{ $category->products_count }} власних товарів ·
                {{ $baseFiltersCount }} основних · {{ $specFiltersText }}
                @if($trashMode && $category->deleted_at)
                    · видалено {{ $category->deleted_at->format('d.m.Y H:i') }}
                @endif
            </small>
        </span>

        <span class="category-tree-status {{ $category->is_active ? 'is-active' : 'is-disabled' }}">
            {{ $category->is_active ? 'Активна' : 'Вимкнена' }}
        </span>

        <span class="category-tree-actions">
            @if($trashMode)
                <button
                    type="button"
                    class="category-tree-restore"
                    wire:click="restoreCategory({{ $category->id }})"
                    wire:confirm="Відновити цю категорію разом із підкатегоріями?"
                >
                    Відновити
                </button>
            @else
                <a class="category-tree-add" href="{{ $createProductUrl }}">
                    + Товар
                </a>
                <a class="category-tree-add" href="{{ $createChildUrl }}">
                    + Підкатегорія
                </a>
                <a
                    class="category-tree-edit"
                    href="{{ \App\Filament\Resources\Categories\CategoryResource::getUrl('edit', ['record' => $category]) }}"
                >
                    Налаштувати
                </a>
                <button
                    type="button"
                    class="category-tree-trash"
                    wire:click="trashCategory({{ $category->id }})"
                    wire:confirm="Перемістити категорію та всі підкатегорії в смітник?"
                >
                    У смітник
                </button>
            @endif
        </span>
    </div>

    @if($hasChildren)
        <div class="category-tree-children">
            @foreach($node['children'] as $childIndex => $childNode)
                @include('filament.resources.categories.pages._category-tree-node', [
                    'node' => $childNode,
                    'level' => $level + 1,
                    'isFirst' => $childIndex === 0,
                    'isLast' => $childIndex === count($node['children']) - 1,
                    'openCategoryIds' => $openCategoryIds ?? [],
                    'focusedCategoryId' => $focusedCategoryId ?? null,
                    'showTrash' => $trashMode,
                ])
            @endforeach
        </div>
    @endif
</div>
