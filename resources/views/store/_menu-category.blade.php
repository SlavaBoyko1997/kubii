<div class="menu-branch">
    @if(($category['children_count'] ?? 0) > 0)
        <button class="menu-child" type="button" data-menu-drill>
            <span><strong>{{ $category['name'] }}</strong><small>{{ __('Товарів: :count', ['count' => number_format($category['products_count'])]) }}</small></span>
            <span>→</span>
        </button>
        <template data-menu-template>
            <button class="menu-back" type="button" data-menu-back>
                <span aria-hidden="true">←</span>
                <strong>{{ $category['name'] }}</strong>
            </button>
            <a class="menu-current-link" href="{{ $category['url'] ?? '#' }}">{{ __('Перейти в розділ') }} →</a>
            @foreach(($category['all_children'] ?? $category['children'] ?? []) as $child)
                @include('store._menu-category', ['category' => $child])
            @endforeach
        </template>
    @else
        <a class="menu-child" href="{{ $category['url'] ?? '#' }}">
            <span><strong>{{ $category['name'] }}</strong><small>{{ __('Товарів: :count', ['count' => number_format($category['products_count'])]) }}</small></span>
            <span>→</span>
        </a>
    @endif
</div>
