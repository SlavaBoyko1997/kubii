@php
    $variantCount = (int) ($product->variantGroup?->active_products_count ?? 0);
    $variantCountLabel = \App\Models\Product::variantCountLabel($variantCount);
    $hasColorSwatches = $product->hasColorVariantSwatches();
    $hasSizeOptions = $product->hasSizeVariantOptions();
    $hasCardVariants = $hasColorSwatches || $hasSizeOptions;
    $resolvedCartProductIds = ($cacheSafeProductCards ?? false) ? [] : $cartProductIds;
@endphp
<article class="product-card {{ ! $product->isPurchasable() ? 'is-out-of-stock' : '' }}">
    <a class="product-image" href="{{ $product->url(false) }}">@if($product->discount_percent && ! $product->isPricePending())<span class="discount-label">−{{ $product->discount_percent }}%</span>@endif @if($variantCount > 1 && ! $hasCardVariants)<span class="variant-count-label">{{ $variantCountLabel }}</span>@endif @if($product->isPricePending())<span class="stock-label">{{ __('Очікується надходження') }}</span>@elseif($product->stock < 1)<span class="stock-label">{{ __('Немає в наявності') }}</span>@elseif($product->stock < 2)<span class="low-stock-label">{{ __('Скоро закінчується') }}</span>@endif<img src="{{ $product->imageUrl() }}" alt="{{ $product->name }}" width="480" height="480" loading="lazy" decoding="async"></a>
    <div class="product-body">
        <a class="product-title" href="{{ $product->url(false) }}">{{ $product->name }}</a>
        <div class="card-rating">@include('store._rating', ['rating' => $product->visible_reviews_avg_rating]) <span>{{ $product->visible_reviews_count }} {{ __('відгуків') }}</span></div>
        @if($hasColorSwatches)
            @include('store._product-color-swatches', ['product' => $product, 'compact' => true])
        @elseif($hasSizeOptions)
            @include('store._product-size-options', ['product' => $product, 'compact' => true])
        @elseif($variantCount > 1)
            <a class="card-variant-note" href="{{ $product->url(false) }}">{{ __('Є :variants товару', ['variants' => $variantCountLabel]) }}</a>
        @endif
        <div class="price-row">
            <span class="product-price {{ $product->discount_percent && ! $product->isPricePending() ? 'has-discount' : '' }}">@if($product->isPricePending())<strong class="price-pending">{{ __('Ціну ще не розраховано.') }}</strong>@else @if($product->discount_percent)<del>{{ number_format($product->price, 0, ',', ' ') }} ₴</del>@endif<strong>{{ number_format($product->salePrice(), 0, ',', ' ') }} ₴</strong>@endif</span>
            <div class="card-buy-actions">
                @include('store._product-list-actions', ['product' => $product, 'class' => 'card-list-actions'])
                @if($product->isPurchasable())
                    <form action="{{ localized_route('cart.store', $product, false) }}" method="POST" data-cart-form data-cart-product="{{ $product->id }}" data-analytics-item='@json(\App\Support\GoogleAnalytics::productItem($product))'>
                        @csrf
                        <button class="buy-button {{ in_array($product->id, $resolvedCartProductIds) ? 'in-cart' : '' }}" data-cart-button data-cart-default-label="{{ __('Купити') }}" data-cart-active-label="{{ __('У кошику') }}">{{ in_array($product->id, $resolvedCartProductIds) ? __('У кошику') : __('Купити') }}</button>
                    </form>
                @endif
            </div>
        </div>
        @if($product->isPurchasable())<button class="quick-order-link" type="button" data-open-quick-order data-product-id="{{ $product->id }}" data-product-name="{{ $product->name }}">{{ __('Швидке замовлення') }}</button>@endif
        @if($product->isPricePending())<p class="out-of-stock-text">{{ __('Очікується надходження') }}</p>@elseif($product->stock < 1)<p class="out-of-stock-text">{{ __('Очікується надходження') }}</p>@endif
    </div>
</article>
