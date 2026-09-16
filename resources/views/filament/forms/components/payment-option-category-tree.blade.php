@php
    use App\Support\CategoryTreeBuilder;

    $statePath = $getStatePath();
    $wireModelAttribute = $applyStateBindingModifiers('wire:model');
    $categoryTree = app(CategoryTreeBuilder::class)->nested();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="payment-category-tree">
        <div class="payment-category-tree-intro">
            Оберіть категорії, для яких доступний цей спосіб оплати. Якщо нічого не відмічено —
            спосіб оплати буде доступний для всіх категорій.
        </div>

        <div class="payment-category-tree-actions">
            <button type="button" class="payment-category-tree-action" wire:click="$set('{{ $statePath }}', {{ json_encode(app(CategoryTreeBuilder::class)->allIds()) }})">
                Обрати всі
            </button>
            <button type="button" class="payment-category-tree-action" wire:click="$set('{{ $statePath }}', [])">
                Зняти всі
            </button>
        </div>

        <div class="payment-category-tree-list">
            @foreach($categoryTree as $node)
                <div class="payment-category-tree-root">
                    @include('filament.forms.components._payment-option-category-tree-node', [
                        'node' => $node,
                        'level' => 0,
                        'statePath' => $statePath,
                        'wireModelAttribute' => $wireModelAttribute,
                    ])
                </div>
            @endforeach
        </div>
    </div>

    <style>
        .payment-category-tree-intro {
            margin-bottom: 14px;
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
        }

        .payment-category-tree-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
        }

        .payment-category-tree-action {
            border: 1px solid rgba(148, 163, 184, .35);
            border-radius: 8px;
            background: #fff;
            padding: 7px 12px;
            color: #315f45;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .payment-category-tree-action:hover {
            background: #f8fafc;
        }

        .payment-category-tree-root {
            border: 1px solid rgba(148, 163, 184, .25);
            border-radius: 14px;
            background: var(--gray-0, #fff);
            box-shadow: 0 1px 3px rgba(15, 23, 42, .06);
        }

        .payment-category-tree-list {
            display: grid;
            gap: 14px;
        }

        .payment-category-tree-node[open] > .payment-category-tree-summary {
            background: rgba(var(--primary-50), .72);
        }

        .payment-category-tree-summary {
            display: grid;
            grid-template-columns: auto auto minmax(0, 1fr) auto;
            align-items: center;
            gap: 12px;
            min-height: 58px;
            padding: 10px 14px;
            border-radius: 11px;
            cursor: pointer;
            list-style: none;
        }

        .payment-category-tree-summary::-webkit-details-marker {
            display: none;
        }

        .payment-category-tree-summary:hover {
            background: #f8fafc;
        }

        .payment-category-tree-toggle {
            display: inline-flex;
            width: 28px;
            height: 28px;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: #f1f5f9;
            color: #475569;
            font-size: 13px;
            font-weight: 800;
        }

        details[open] > summary .payment-category-tree-toggle {
            transform: rotate(90deg);
        }

        .payment-category-tree-toggle.is-empty {
            opacity: .35;
            transform: none !important;
        }

        .payment-category-tree-checkbox {
            display: inline-flex;
            align-items: center;
        }

        .payment-category-tree-name strong,
        .payment-category-tree-name small {
            display: block;
        }

        .payment-category-tree-name strong {
            color: #17211c;
            font-size: 15px;
        }

        .payment-category-tree-name small {
            margin-top: 3px;
            color: #64748b;
            font-size: 12px;
        }

        .payment-category-tree-status {
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .payment-category-tree-status.is-active {
            background: #dcfce7;
            color: #166534;
        }

        .payment-category-tree-status.is-disabled {
            background: #fee2e2;
            color: #991b1b;
        }

        .payment-category-tree-children {
            margin: 0 14px 8px 28px;
            padding-left: 16px;
            border-left: 2px solid #e2e8f0;
        }
    </style>
</x-dynamic-component>
