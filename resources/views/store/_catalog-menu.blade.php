@foreach($menuCategories as $category)
    <div class="menu-group">
        <a class="menu-parent" href="{{ $category['url'] ?? '#' }}">
            <span class="menu-parent-media">@include('store._category-image', ['category' => $category])</span>
            <span>
                <strong>{{ $category['name'] }}</strong>
                <small>{{ number_format($category['products_count']) }} {{ __('товарів') }}</small>
            </span>
        </a>
        <div class="menu-children">
            @foreach($category['children'] as $child)
                @include('store._menu-category', ['category' => $child])
            @endforeach
        </div>
        @if($category['children_count'] > count($category['children']))
            <button class="menu-more" type="button" data-menu-drill>{{ __('Усі підрозділи: :count', ['count' => $category['children_count']]) }} →</button>
            <template data-menu-template>
                <button class="menu-back" type="button" data-menu-back>
                    <span aria-hidden="true">←</span>
                    <strong>{{ $category['name'] }}</strong>
                </button>
                <a class="menu-current-link" href="{{ $category['url'] ?? '#' }}">{{ __('Перейти в розділ') }} →</a>
                @foreach(($category['all_children'] ?? $category['children']) as $child)
                    @include('store._menu-category', ['category' => $child])
                @endforeach
            </template>
        @endif
    </div>
@endforeach
