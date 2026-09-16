<div class="catalog-title">
    <div>@if($search)<p>{{ __('Результати пошуку для «:query»', ['query' => $search]) }}</p>@endif</div>
    <span>{{ $products->total() }} {{ __('товарів') }}</span>
</div>
@if($hasActiveFilters)
    <div class="active-filters">
        <span>{{ __('Обрані фільтри:') }}</span>
        @foreach($activeFilters as $filter)<a class="active-filter-chip" href="{{ $filter['url'] }}">{{ $filter['label'] }} <i aria-hidden="true">×</i></a>@endforeach
        <a class="active-filter-reset" href="{{ $resetUrl }}">{{ __('Скинути все') }}</a>
    </div>
@endif
@if ($products->isEmpty())
    <div class="empty-state"><h2>{{ __('Нічого не знайдено') }}</h2><p>{{ __('Спробуйте змінити фільтри або пошуковий запит.') }}</p></div>
@else
    <div class="product-grid catalog-products" data-product-grid>
        @foreach ($products as $product)
            @include('store._product-card')
        @endforeach
    </div>
    @if ($includePaginationNav ?? true)
        @include('store._catalog-results-nav')
    @endif
@endif
