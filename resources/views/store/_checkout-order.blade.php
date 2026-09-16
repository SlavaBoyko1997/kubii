<div class="checkout-order-head">
    <div>
        <span class="eyebrow">{{ __('Перевірте перед підтвердженням') }}</span>
        <h2><i>2</i> {{ __('Ваше замовлення') }}</h2>
        <small data-checkout-section-status>{{ $items->isEmpty() ? __('Додайте товари до кошика') : __('Товари додано, перевірте кількість') }}</small>
    </div>
    <button class="edit-order-link" type="button" data-open-cart aria-label="{{ __('Редагувати замовлення') }}">✎ {{ __('Редагувати') }}</button>
</div>
@if($items->isEmpty())
    <div class="checkout-order-empty">
        <h3>{{ __('У кошику немає товарів') }}</h3>
        <p>{{ __('Додайте товари або поверніться до каталогу, щоб продовжити оформлення.') }}</p>
    </div>
@else
    <div class="checkout-order-list">
        @foreach($items as $item)
            @php
                $product = $item['product'];
                $salePrice = $product->salePrice();
                $hasDiscount = $product->price > $salePrice && ! $product->isPricePending();
                $discountPercent = $hasDiscount ? ($product->discount_percent ?: round((($product->price - $salePrice) / $product->price) * 100)) : null;
            @endphp
            <article class="checkout-order-item">
                <a class="checkout-order-image" href="{{ $product->url() }}">
                    @if($hasDiscount)<span class="discount-label">−{{ $discountPercent }}%</span>@endif
                    <img src="{{ $product->imageUrl() }}" alt="{{ $product->name }}" loading="lazy" decoding="async">
                </a>
                <div class="checkout-order-info">
                    <a href="{{ $product->url() }}"><strong>{{ $product->name }}</strong></a>
                    <span>{{ __('Кількість:') }} {{ $item['quantity'] }}</span>
                    @if($product->sku)<span>{{ __('Код товару:') }} {{ $product->sku }}</span>@endif
                </div>
                <div class="checkout-order-price">
                    @if($hasDiscount)<del>{{ number_format($product->price, 0, ',', ' ') }} ₴</del>@endif
                    <strong class="{{ $hasDiscount ? 'sale-price' : '' }}">{{ number_format($salePrice, 0, ',', ' ') }} ₴</strong>
                    <span>{{ number_format($item['subtotal'], 0, ',', ' ') }} ₴ {{ __('за :count шт.', ['count' => $item['quantity']]) }}</span>
                </div>
            </article>
        @endforeach
    </div>
@endif
