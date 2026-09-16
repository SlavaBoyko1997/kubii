<x-filament-panels::page>
    <style>
        .category-tree-intro,
        .category-tree-root {
            border: 1px solid rgba(148, 163, 184, .25);
            border-radius: 14px;
            background: var(--gray-0, #fff);
            box-shadow: 0 1px 3px rgba(15, 23, 42, .06);
        }

        .category-tree-intro {
            margin-bottom: 18px;
            padding: 16px 18px;
            color: #64748b;
            font-size: 14px;
        }

        .category-tree-list {
            display: grid;
            gap: 14px;
        }

        .category-tree-node {
            position: relative;
        }

        .category-tree-node.is-open > .category-tree-summary {
            background: rgba(var(--primary-50), .45);
        }

        .category-tree-node > .category-tree-children {
            display: none;
        }

        .category-tree-node.is-open > .category-tree-children {
            display: block;
        }

        .category-tree-summary {
            display: grid;
            grid-template-columns: auto auto auto minmax(0, 1fr) auto auto;
            align-items: center;
            gap: 12px;
            min-height: 62px;
            padding: 10px 14px;
            border-radius: 11px;
            list-style: none;
            transition: background-color .15s ease, box-shadow .15s ease;
        }

        .category-tree-summary.is-drop-target,
        .category-tree-root-dropzone.is-drop-target {
            background: rgba(var(--primary-50), .92);
            box-shadow: inset 0 0 0 2px rgba(var(--primary-500), .45);
        }

        .category-tree-summary.is-drop-before {
            box-shadow: inset 0 3px 0 0 rgba(var(--primary-500), .9);
        }

        .category-tree-summary.is-drop-after {
            box-shadow: inset 0 -3px 0 0 rgba(var(--primary-500), .9);
        }

        .category-tree-summary.is-focused {
            background: rgba(var(--primary-50), .72);
            box-shadow: inset 0 0 0 1px rgba(var(--primary-500), .28);
        }

        .category-tree-sort-btn.is-loading {
            opacity: .55;
        }

        .category-tree-drag-handle {
            display: inline-flex;
            width: 28px;
            height: 28px;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: #eef2f7;
            color: #64748b;
            cursor: grab;
            font-size: 14px;
            line-height: 1;
            touch-action: none;
            user-select: none;
            -webkit-user-select: none;
        }

        .category-tree-drag-handle.is-dragging,
        .category-tree-dnd.is-dragging .category-tree-drag-handle {
            cursor: grabbing;
        }

        .category-tree-drag-ghost {
            position: fixed;
            top: 0;
            left: 0;
            z-index: 99999;
            max-width: 280px;
            padding: 8px 12px;
            border-radius: 10px;
            background: #315f45;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .22);
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            pointer-events: none;
        }

        .category-tree-root-dropzone {
            display: none;
            margin-bottom: 14px;
            padding: 14px 16px;
            border: 2px dashed rgba(49, 93, 77, .28);
            border-radius: 12px;
            color: #315f45;
            font-size: 13px;
            font-weight: 700;
            text-align: center;
            transition: background-color .15s ease, box-shadow .15s ease;
        }

        .category-tree-dnd.is-dragging .category-tree-root-dropzone {
            display: block;
        }

        .category-tree-toggle {
            display: inline-flex;
            width: 28px;
            height: 28px;
            align-items: center;
            justify-content: center;
            padding: 0;
            border: 0;
            border-radius: 8px;
            background: #f1f5f9;
            color: #475569;
            cursor: pointer;
            font-size: 13px;
            font-weight: 800;
            transition: transform .15s ease;
        }

        .category-tree-node.is-open > .category-tree-summary .category-tree-toggle:not(.is-empty) {
            transform: rotate(90deg);
        }

        .category-tree-toggle.is-empty {
            opacity: .35;
            cursor: default;
            transform: none !important;
        }

        .category-tree-sort {
            display: inline-grid;
            grid-template-columns: repeat(2, 28px);
            gap: 4px;
        }

        .category-tree-sort-btn {
            display: inline-flex;
            width: 28px;
            height: 28px;
            align-items: center;
            justify-content: center;
            padding: 0;
            border: 1px solid #dbe3ea;
            border-radius: 8px;
            background: #fff;
            color: #315f45;
            cursor: pointer;
            font-size: 14px;
            font-weight: 800;
            line-height: 1;
            transition: background-color .15s ease, border-color .15s ease, opacity .15s ease;
        }

        .category-tree-sort-btn:hover:not(:disabled) {
            border-color: rgba(49, 93, 77, .35);
            background: #eef6f0;
        }

        .category-tree-sort-btn:disabled {
            cursor: default;
            opacity: .35;
        }

        .category-tree-name {
            min-width: 0;
        }

        .category-tree-name strong,
        .category-tree-name small {
            display: block;
        }

        .category-tree-name strong {
            overflow: hidden;
            color: #17211c;
            font-size: 15px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .category-tree-name small {
            margin-top: 3px;
            color: #64748b;
            font-size: 12px;
        }

        .category-tree-status {
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .category-tree-status.is-active {
            background: #dcfce7;
            color: #166534;
        }

        .category-tree-status.is-disabled {
            background: #fee2e2;
            color: #991b1b;
        }

        .category-tree-actions {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .category-tree-trash,
        .category-tree-restore {
            display: inline-flex;
            align-items: center;
            min-height: 34px;
            padding: 7px 12px;
            border: 0;
            border-radius: 9px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            cursor: pointer;
        }

        .category-tree-trash {
            border: 1px solid rgba(185, 28, 28, .18);
            background: #fef2f2;
            color: #991b1b;
        }

        .category-tree-trash:hover {
            background: #fee2e2;
        }

        .category-tree-restore {
            border: 1px solid rgba(49, 93, 77, .22);
            background: #f4f8f5;
            color: #244b35;
        }

        .category-tree-restore:hover {
            background: #e8f1eb;
        }

        .category-tree-empty {
            padding: 28px 18px;
            border: 1px dashed rgba(148, 163, 184, .45);
            border-radius: 14px;
            color: #64748b;
            font-size: 14px;
            text-align: center;
        }

        .category-tree-add,
        .category-tree-edit {
            display: inline-flex;
            align-items: center;
            min-height: 34px;
            padding: 7px 12px;
            border-radius: 9px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }

        .category-tree-add {
            border: 1px solid rgba(49, 93, 77, .22);
            background: #f4f8f5;
            color: #244b35;
        }

        .category-tree-add:hover {
            background: #e8f1eb;
        }

        .category-tree-edit {
            background: #315f45;
            color: #fff;
        }

        .category-tree-edit:hover {
            background: #244b35;
        }

        .category-tree-summary.is-trash-mode {
            grid-template-columns: auto minmax(0, 1fr) auto auto;
        }

        .category-tree-children {
            margin: 0 14px 8px 28px;
            padding-left: 16px;
            border-left: 2px solid #e2e8f0;
        }

        .category-tree-children .category-tree-summary {
            min-height: 54px;
            border-radius: 9px;
        }

        @media (prefers-color-scheme: dark) {
            .category-tree-intro,
            .category-tree-root {
                border-color: rgba(255, 255, 255, .1);
                background: #111827;
            }

            .category-tree-node.is-open > .category-tree-summary {
                background: rgba(255, 255, 255, .05);
            }

            .category-tree-toggle {
                background: rgba(255, 255, 255, .08);
                color: #cbd5e1;
            }

            .category-tree-drag-handle {
                background: rgba(255, 255, 255, .08);
                color: #94a3b8;
            }

            .category-tree-name strong {
                color: #f8fafc;
            }

            .category-tree-name small,
            .category-tree-intro {
                color: #94a3b8;
            }

            .category-tree-children {
                border-color: rgba(255, 255, 255, .12);
            }
        }

        @media (max-width: 700px) {
            .category-tree-summary {
                grid-template-columns: auto auto auto minmax(0, 1fr) auto;
            }

            .category-tree-sort {
                grid-column: 1 / 2;
                grid-row: 2;
            }

            .category-tree-actions {
                grid-column: 1 / -1;
                justify-content: stretch;
            }

            .category-tree-add,
            .category-tree-edit {
                flex: 1;
                justify-content: center;
            }

            .category-tree-status {
                display: none;
            }

            .category-tree-edit {
                padding-inline: 9px;
            }

            .category-tree-children {
                margin-left: 14px;
                padding-left: 9px;
            }
        }
    </style>

    <div class="category-tree-intro">
        @if($showTrash)
            Тут зберігаються видалені категорії разом із підкатегоріями. Відновлення повертає обрану гілку назад у дерево.
        @else
            Неактивні категорії показуються в дереві. Об’єднані дублікати (перенесені в іншу категорію) приховані й видаляються з бази.
            Використовуйте стрілки біля кожної категорії: <strong>↑↓</strong> — порядок на одному рівні,
            <strong>←</strong> — винести на рівень вище, <strong>→</strong> — зробити підкатегорією попередньої.
            Ручку ⠿ можна використовувати для перетягування мишкою.
        @endif
    </div>

    @if($showTrash)
        <div class="category-tree-list">
            @forelse($categoryTree as $index => $node)
                <div class="category-tree-root">
                    @include('filament.resources.categories.pages._category-tree-node', [
                        'node' => $node,
                        'level' => 0,
                        'isFirst' => $index === 0,
                        'isLast' => $index === count($categoryTree) - 1,
                        'openCategoryIds' => $openCategoryIds,
                        'focusedCategoryId' => $focusedCategoryId,
                        'showTrash' => true,
                    ])
                </div>
            @empty
                <div class="category-tree-empty">Смітник порожній.</div>
            @endforelse
        </div>
    @else
    <div class="category-tree-dnd" data-livewire-id="{{ $livewireId }}">
        <div class="category-tree-root-dropzone">
            Перетягніть сюди, щоб зробити категорію кореневою
        </div>

        <div class="category-tree-list">
            @foreach($categoryTree as $index => $node)
                <div class="category-tree-root">
                    @include('filament.resources.categories.pages._category-tree-node', [
                        'node' => $node,
                        'level' => 0,
                        'isFirst' => $index === 0,
                        'isLast' => $index === count($categoryTree) - 1,
                        'openCategoryIds' => $openCategoryIds,
                        'focusedCategoryId' => $focusedCategoryId,
                        'showTrash' => false,
                    ])
                </div>
            @endforeach
        </div>
    </div>
    @endif

    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('category-tree-focus', ({ categoryId }) => {
                requestAnimationFrame(() => {
                    document.getElementById(`category-tree-node-${categoryId}`)?.scrollIntoView({
                        block: 'nearest',
                        behavior: 'smooth',
                    });
                });
            });
        });
    </script>
</x-filament-panels::page>
