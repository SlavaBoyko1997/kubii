@php
    $resolvedFavoriteIds = ($cacheSafeProductCards ?? false) ? [] : $favoriteIds;
    $resolvedComparisonIds = ($cacheSafeProductCards ?? false) ? [] : $comparisonIds;
@endphp
<div class="{{ $class ?? 'product-card-actions' }}">
    <form action="{{ localized_route('favorites.toggle', $product, false) }}" method="POST" data-product-list-form>
        @csrf
        <button class="product-list-button {{ in_array($product->id, $resolvedFavoriteIds) ? 'is-active' : '' }}" type="submit" data-favorite-product="{{ $product->id }}" aria-label="{{ __('Додати в обране') }}" title="{{ __('Додати в обране') }}">{{ in_array($product->id, $resolvedFavoriteIds) ? '♥' : '♡' }}</button>
    </form>
    <form action="{{ localized_route('comparison.toggle', $product, false) }}" method="POST" data-product-list-form>
        @csrf
        <button class="product-list-button compare-toggle {{ in_array($product->id, $resolvedComparisonIds) ? 'is-active' : '' }}" type="submit" data-comparison-product="{{ $product->id }}" aria-label="{{ __('Додати до порівняння') }}" title="{{ __('Додати до порівняння') }}">⇄</button>
    </form>
</div>
