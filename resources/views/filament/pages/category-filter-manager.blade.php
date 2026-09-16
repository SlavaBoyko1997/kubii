<x-filament-panels::page>
    <style>
        .category-filter-page {
            display: grid;
            gap: 18px;
            max-width: 1120px;
        }

        .category-filter-card {
            padding: 22px;
            border: 1px solid rgba(148, 163, 184, .24);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 1px 4px rgba(15, 23, 42, .05);
        }

        .category-filter-head {
            display: grid;
            gap: 8px;
            margin-bottom: 18px;
        }

        .category-filter-title {
            margin: 0;
            color: #17211c;
            font-size: 18px;
            font-weight: 750;
        }

        .category-filter-note {
            margin: 0;
            color: #64748b;
            font-size: 13px;
            line-height: 1.55;
        }

        .category-filter-select {
            width: 100%;
            min-height: 44px;
            padding: 8px 12px;
            border: 1px solid rgba(100, 116, 139, .35);
            border-radius: 10px;
            background: #fff;
            color: #17211c;
            font-size: 14px;
        }

        .category-filter-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .category-filter-option {
            display: grid;
            gap: 12px;
            padding: 11px 12px;
            border: 1px solid rgba(148, 163, 184, .24);
            border-radius: 10px;
            background: #f8fafc;
            color: #17211c;
            font-size: 14px;
        }

        .category-filter-option-main {
            display: grid;
            grid-template-columns: 20px minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
        }

        .category-filter-option-main input {
            width: 18px;
            height: 18px;
            accent-color: #15803d;
        }

        .category-filter-option small {
            color: #64748b;
            font-size: 12px;
            white-space: nowrap;
        }

        .category-filter-label-fields {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .category-filter-label-fields input {
            width: 100%;
            min-height: 36px;
            padding: 7px 9px;
            border: 1px solid rgba(100, 116, 139, .28);
            border-radius: 8px;
            background: #fff;
            color: #17211c;
            font-size: 13px;
        }

        .category-filter-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            align-items: center;
            flex-wrap: wrap;
        }

        .category-filter-auto {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-right: auto;
            color: #475569;
            font-size: 13px;
        }

        .category-filter-auto input {
            width: 82px;
            min-height: 38px;
            padding: 7px 9px;
            border: 1px solid rgba(100, 116, 139, .28);
            border-radius: 8px;
            background: #fff;
            color: #17211c;
        }

        .category-filter-check {
            display: inline-flex;
            gap: 8px;
            align-items: center;
            min-height: 42px;
            color: #475569;
            font-size: 13px;
            font-weight: 700;
        }

        .category-filter-check input {
            width: 18px;
            height: 18px;
            accent-color: #15803d;
        }

        .category-filter-secondary {
            min-height: 42px;
            padding: 10px 14px;
            border: 1px solid rgba(100, 116, 139, .28);
            border-radius: 10px;
            background: #fff;
            color: #17211c;
            cursor: pointer;
            font-weight: 700;
        }

        .category-filter-save {
            min-height: 42px;
            padding: 10px 18px;
            border: 0;
            border-radius: 10px;
            background: #15803d;
            color: #fff;
            cursor: pointer;
            font-weight: 700;
        }

        .category-filter-save:disabled {
            cursor: wait;
            opacity: .6;
        }

        .category-filter-secondary:disabled {
            cursor: wait;
            opacity: .6;
        }

        @media (prefers-color-scheme: dark) {
            .category-filter-card {
                border-color: rgba(255, 255, 255, .1);
                background: #111827;
            }

            .category-filter-title,
            .category-filter-option,
            .category-filter-select,
            .category-filter-secondary,
            .category-filter-auto,
            .category-filter-auto input,
            .category-filter-check,
            .category-filter-label-fields input {
                color: #f8fafc;
            }

            .category-filter-note,
            .category-filter-option small {
                color: #94a3b8;
            }

            .category-filter-select,
            .category-filter-option,
            .category-filter-auto input,
            .category-filter-secondary,
            .category-filter-label-fields input {
                border-color: rgba(255, 255, 255, .12);
                background: rgba(255, 255, 255, .06);
            }

            .category-filter-auto input {
                color-scheme: dark;
            }
        }

        @media (max-width: 760px) {
            .category-filter-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="category-filter-page">
        <section class="category-filter-card">
            <div class="category-filter-head">
                <h2 class="category-filter-title">Категорія</h2>
                <p class="category-filter-note">Показані фільтри рахуються по вибраній категорії та всіх її дочірніх категоріях.</p>
            </div>

            <select class="category-filter-select" wire:model.live="categoryId">
                @foreach($categoryOptions as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>

            @if($categoryName)
                <p class="category-filter-note" style="margin-top: 12px;">
                    {{ $categoryName }} · {{ $settingsMode }}
                </p>
            @endif
        </section>

        <section class="category-filter-card">
            <div class="category-filter-head">
                <h2 class="category-filter-title">Основні фільтри</h2>
                <p class="category-filter-note">Вимикай ті, які не мають показуватись у каталозі. Число справа показує кількість значень або товарів, де цей фільтр має сенс.</p>
            </div>

            <div class="category-filter-grid">
                @foreach($availableBaseFilters as $key => $filter)
                    @php($labelInput = $filterLabelInputs[$filter['label_input_id']] ?? null)
                    <div class="category-filter-option">
                        <label class="category-filter-option-main">
                            <input type="checkbox" value="{{ $key }}" wire:model="baseFilters">
                            <span>{{ $filter['label'] }}</span>
                            <small>{{ $filter['count'] }}</small>
                        </label>
                        @if($labelInput)
                            <div class="category-filter-label-fields">
                                <input type="text" wire:model.blur="filterLabelInputs.{{ $filter['label_input_id'] }}.uk" placeholder="UK: {{ $labelInput['placeholder_uk'] }}">
                                <input type="text" wire:model.blur="filterLabelInputs.{{ $filter['label_input_id'] }}.ru" placeholder="RU: {{ $labelInput['placeholder_ru'] }}">
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <section class="category-filter-card">
            <div class="category-filter-head">
                <h2 class="category-filter-title">Фільтри з характеристик</h2>
                <p class="category-filter-note">Це характеристики з товарів вибраної категорії та дочірніх категорій. Вимкнені ключі не будуть доступні як фільтр на сайті.</p>
            </div>

            @if($availableSpecFilters === [])
                <p class="category-filter-note">Для цієї категорії поки немає характеристик, які можна показати як фільтри.</p>
            @else
                <div class="category-filter-grid">
                    @foreach($availableSpecFilters as $key => $filter)
                        @php($labelInput = $filterLabelInputs[$filter['label_input_id']] ?? null)
                        <div class="category-filter-option">
                            <label class="category-filter-option-main">
                                <input type="checkbox" value="{{ $key }}" wire:model="specFilters">
                                <span>{{ $filter['label'] }}</span>
                                <small>{{ $filter['count'] ?? 'збережено' }}</small>
                            </label>
                            @if($labelInput)
                                <div class="category-filter-label-fields">
                                    <input type="text" wire:model.blur="filterLabelInputs.{{ $filter['label_input_id'] }}.uk" placeholder="UK: {{ $labelInput['placeholder_uk'] }}">
                                    <input type="text" wire:model.blur="filterLabelInputs.{{ $filter['label_input_id'] }}.ru" placeholder="RU: {{ $labelInput['placeholder_ru'] }}">
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <div class="category-filter-actions">
            <div class="category-filter-auto">
                <span>Вимкнути фільтри, де менше ніж</span>
                <input type="number" min="1" step="1" wire:model="minimumFilterProducts">
                <span>товарів</span>
            </div>
            <button
                type="button"
                class="category-filter-secondary"
                wire:click="autoDisableSparseFilters"
                wire:loading.attr="disabled"
                wire:target="autoDisableSparseFilters"
            >
                Автоматично вимкнути
            </button>
            <button
                type="button"
                class="category-filter-secondary"
                wire:click="autoDisableLatinNamedSpecFilters"
                wire:loading.attr="disabled"
                wire:target="autoDisableLatinNamedSpecFilters"
            >
                Вимкнути латинські назви
            </button>
            <button
                type="button"
                class="category-filter-secondary"
                wire:click="applyToChildren"
                wire:loading.attr="disabled"
                wire:target="applyToChildren"
            >
                Застосувати в дочірніх
            </button>
            <label class="category-filter-check">
                <input type="checkbox" wire:model="applyToChildrenOnSave">
                <span>Застосувати також у дочірніх</span>
            </label>
            <button
                type="button"
                class="category-filter-save"
                wire:click="save"
                wire:loading.attr="disabled"
                wire:target="save"
            >
                Зберегти фільтри
            </button>
        </div>
    </div>
</x-filament-panels::page>
