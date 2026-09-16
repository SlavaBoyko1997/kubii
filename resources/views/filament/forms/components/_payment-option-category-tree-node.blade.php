@php
    $category = $node['category'];
    $hasChildren = $node['children'] !== [];
@endphp

<details class="payment-category-tree-node" @if($level === 0) open @endif>
    <summary class="payment-category-tree-summary">
        <span class="payment-category-tree-toggle {{ $hasChildren ? '' : 'is-empty' }}">
            {{ $hasChildren ? '›' : '•' }}
        </span>

        <label class="payment-category-tree-checkbox" onclick="event.stopPropagation()">
            <input
                type="checkbox"
                class="fi-checkbox-input"
                value="{{ $category->id }}"
                {{ $wireModelAttribute }}="{{ $statePath }}"
            />
        </label>

        <span class="payment-category-tree-name">
            <strong>{{ $category->getRawOriginal('name') }}</strong>
            <small>{{ $category->products_count }} власних товарів</small>
        </span>

        <span class="payment-category-tree-status {{ $category->is_active ? 'is-active' : 'is-disabled' }}">
            {{ $category->is_active ? 'Активна' : 'Вимкнена' }}
        </span>
    </summary>

    @if($hasChildren)
        <div class="payment-category-tree-children">
            @foreach($node['children'] as $childNode)
                @include('filament.forms.components._payment-option-category-tree-node', [
                    'node' => $childNode,
                    'level' => $level + 1,
                    'statePath' => $statePath,
                    'wireModelAttribute' => $wireModelAttribute,
                ])
            @endforeach
        </div>
    @endif
</details>
