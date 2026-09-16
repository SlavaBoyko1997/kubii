<div class="drawer-head"><div><span>{{ __('Ваші покупки') }}</span><h2>{{ __('Кошик') }}</h2></div><button type="button" data-close-cart aria-label="{{ __('Закрити') }}">×</button></div>
@if ($drawerItems->isEmpty())
    <div class="drawer-empty"><span>🛒</span><h3>{{ __('Кошик поки порожній') }}</h3><p>{{ __('Зазирніть у каталог і знайдіть спорядження для наступної пригоди.') }}</p><button class="primary-button" type="button" data-open-catalog>{{ __('Перейти до каталогу') }}</button></div>
@else
    <div class="drawer-items-toolbar">
        <span>{{ __('Товари') }} · {{ $drawerItems->sum('quantity') }}</span>
        <form action="{{ localized_route('cart.clear') }}" method="POST" data-cart-form>@csrf @method('DELETE')<button type="submit" class="drawer-cart-link">{{ __('Очистити кошик') }}</button></form>
    </div>
    <div class="drawer-items">
        @foreach ($drawerItems as $item)
            @php
                $product = $item['product'];
                $salePrice = $product->salePrice();
                $hasDiscount = $product->price > $salePrice && ! $product->isPricePending();
                $discountPercent = $hasDiscount ? ($product->discount_percent ?: round((($product->price - $salePrice) / $product->price) * 100)) : null;
            @endphp
            <article class="drawer-item">
                <a href="{{ $product->url() }}"><img src="{{ $product->imageUrl() }}" alt="{{ $product->name }}" loading="lazy" decoding="async"></a>
                <div>
                    <a href="{{ $product->url() }}"><strong>{{ $product->name }}</strong></a>
                    <span class="drawer-item-price">
                        @if($hasDiscount)<del>{{ number_format($product->price, 0, ',', ' ') }} ₴</del>@endif
                        <strong class="{{ $hasDiscount ? 'sale-price' : '' }}">{{ number_format($salePrice, 0, ',', ' ') }} ₴</strong>
                        @if($hasDiscount)<em>−{{ $discountPercent }}%</em>@endif
                    </span>
                    <form class="drawer-quantity" action="{{ localized_route('cart.update', $product) }}" method="POST" data-cart-form data-cart-quantity-form>@csrf @method('PATCH')<button type="button" data-quantity-change="-1">−</button><input type="number" name="quantity" min="0" max="{{ $product->stock }}" value="{{ $item['quantity'] }}"><button type="button" data-quantity-change="1">+</button></form>
                </div>
                <form action="{{ localized_route('cart.destroy', $product) }}" method="POST" data-cart-form>@csrf @method('DELETE')<button type="submit" class="remove-button" data-cart-remove aria-label="{{ __('Видалити') }}">×</button></form>
            </article>
        @endforeach
    </div>
    @php
        $drawerRegularTotal = $drawerItems->sum(fn ($item) => $item['product']->price * $item['quantity']);
        $drawerSavings = max(0, $drawerRegularTotal - $drawerTotal);
    @endphp
    <div class="drawer-footer">
        <div class="drawer-total"><span>{{ __('Разом') }}</span><strong>{{ number_format($drawerTotal, 0, ',', ' ') }} ₴</strong></div>
        @if($drawerSavings > 0)<div class="drawer-saving"><span>{{ __('Ваша вигода') }}</span><strong>{{ number_format($drawerSavings, 0, ',', ' ') }} ₴</strong></div>@endif
        @include('store._cart-delivery-summary', ['total' => $drawerTotal, 'context' => 'drawer'])
        <a class="primary-button" href="{{ localized_route('checkout.create') }}">{{ __('Оформити замовлення') }}</a>
    </div>
@endif
