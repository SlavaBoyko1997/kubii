@if($search)<input type="hidden" name="search" value="{{ $search }}">@endif
<h3>{{ __('Фільтри') }}</h3>
@foreach($visibleBaseFilters as $baseFilter)
    @switch($baseFilter)
        @case('brand')
            @include('store._filter-options', ['title' => $baseFilterLabels['brand'] ?? __('Бренд'), 'name' => 'brand', 'values' => $availableBrands, 'selected' => $selectedBrands, 'searchPlaceholder' => __('Пошук по бренду'), 'trackingKey' => 'brand'])
            @break
        @case('catalog_category')
            @include('store._filter-options', ['title' => $baseFilterLabels['catalog_category'] ?? __('Категорія'), 'name' => 'catalog_category', 'values' => $availableCatalogCategories ?? [], 'selected' => $selectedCatalogCategories ?? [], 'searchPlaceholder' => __('Пошук по категорії'), 'trackingKey' => 'catalog_category'])
            @break
        @case('model')
            @include('store._filter-options', ['title' => $baseFilterLabels['model'] ?? __('Модель'), 'name' => 'model', 'values' => $availableModels, 'selected' => $selectedModels, 'trackingKey' => 'model'])
            @break
        @case('sale')
            @if($hasSaleProducts)
                <label class="sale-filter">
                    <input type="checkbox" name="on_sale" value="1" data-filter-key="sale" data-filter-value="{{ $baseFilterLabels['sale'] ?? __('Акційні товари') }}" @checked($onSale)>
                    <span class="sale-filter-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H12l8 8-8 8-8-8V5.5Z"/><circle cx="8" cy="8" r="1.25"/><path d="m9 15 6-6M10 10h.01M14 14h.01"/></svg>
                    </span>
                    <span class="sale-filter-copy">
                        <strong>{{ $baseFilterLabels['sale'] ?? __('Акційні товари') }} @include('store._admin-filter-controls', ['type' => 'base', 'filterKey' => 'sale'])</strong>
                        <small>{{ __('Показати лише товари зі знижкою') }}</small>
                    </span>
                    <span class="sale-filter-control" aria-hidden="true">
                        <span class="sale-filter-state">
                            <span class="sale-filter-state-off">{{ __('Лише зі знижкою') }}</span>
                            <span class="sale-filter-state-on">{{ __('Увімкнено') }}</span>
                        </span>
                        <span class="sale-filter-switch"><i></i></span>
                    </span>
                </label>
            @endif
            @break
        @case('price')
            <fieldset><legend>{{ $baseFilterLabels['price'] ?? __('Ціна') }}, ₴ @include('store._admin-filter-controls', ['type' => 'base', 'filterKey' => 'price'])</legend><div class="price-filter"><input type="number" name="min_price" value="{{ $minPrice }}" data-filter-key="price" data-filter-value="{{ __('Мінімальна ціна') }}" placeholder="{{ $availableMinPrice !== null ? __('від').' '.number_format($availableMinPrice, 0, ',', ' ') : __('від') }}"><input type="number" name="max_price" value="{{ $maxPrice }}" data-filter-key="price" data-filter-value="{{ __('Максимальна ціна') }}" placeholder="{{ $availableMaxPrice !== null ? __('до').' '.number_format($availableMaxPrice, 0, ',', ' ') : __('до') }}"></div></fieldset>
            @break
        @case('season')
            @include('store._filter-options', ['title' => $baseFilterLabels['season'] ?? __('Сезон'), 'name' => 'season', 'values' => $availableSeasons, 'selected' => $selectedSeasons, 'trackingKey' => 'season'])
            @break
        @case('usage_type')
            @include('store._filter-options', ['title' => $baseFilterLabels['usage_type'] ?? __('Тип використання'), 'name' => 'usage_type', 'values' => $availableUsageTypes, 'selected' => $selectedUsageTypes, 'trackingKey' => 'usage_type'])
            @break
        @case('material')
            @include('store._filter-options', ['title' => $baseFilterLabels['material'] ?? __('Матеріал'), 'name' => 'material', 'values' => $availableMaterials, 'selected' => $selectedMaterials, 'trackingKey' => 'material'])
            @break
        @case('weight')
            <fieldset><legend>{{ $baseFilterLabels['weight'] ?? __('Максимальна вага') }}, {{ __('г') }} @include('store._admin-filter-controls', ['type' => 'base', 'filterKey' => 'weight'])</legend><input class="filter-number" type="number" name="max_weight" value="{{ $maxWeight }}" data-filter-key="weight" data-filter-value="{{ $baseFilterLabels['weight'] ?? __('Максимальна вага') }}" placeholder="{{ __('наприклад, 3000') }}"></fieldset>
            @break
        @case('stock')
            <label class="stock-filter"><input type="checkbox" name="in_stock" value="1" data-filter-key="stock" data-filter-value="{{ $baseFilterLabels['stock'] ?? __('Тільки в наявності') }}" @checked($inStock)> {{ $baseFilterLabels['stock'] ?? __('Тільки в наявності') }} @include('store._admin-filter-controls', ['type' => 'base', 'filterKey' => 'stock'])</label>
            @break
    @endswitch
@endforeach
@foreach($dynamicSpecFilters as $specFilter)
    @include('store._filter-options', ['title' => $specFilter['title'], 'name' => 'spec['.$specFilter['key'].']', 'values' => $specFilter['values'], 'selected' => $specFilter['selected'], 'trackingKey' => $specFilter['key']])
@endforeach
<button class="primary-button" data-apply-mobile-filters>{{ __('Застосувати') }}</button>
<a class="reset-filter" href="{{ $resetUrl }}">{{ __('Скинути фільтри') }}</a>
