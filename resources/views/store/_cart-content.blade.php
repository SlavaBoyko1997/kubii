@if ($items->isEmpty())
    <div class="empty-state"><h2>{{ __('Кошик порожній') }}</h2><p>{{ __("Додайте спорядження з каталогу, і воно з'явиться тут.") }}</p><a class="primary-button" href="{{ $catalogEntryUrl }}">{{ __('Перейти до каталогу') }}</a></div>
@else
    <div class="cart-layout">
        <div class="cart-items">
            <div class="cart-items-head">
                <span>{{ __('Товари') }} · {{ $items->sum('quantity') }}</span>
                <form action="{{ localized_route('cart.clear') }}" method="POST" data-cart-form>@csrf @method('DELETE')<button type="submit" class="clear-cart-button">{{ __('Очистити кошик') }}</button></form>
            </div>
            @foreach ($items as $item)
                @php
                    $product = $item['product'];
                    $salePrice = $product->salePrice();
                    $hasDiscount = $product->price > $salePrice && ! $product->isPricePending();
                    $discountPercent = $hasDiscount ? ($product->discount_percent ?: round((($product->price - $salePrice) / $product->price) * 100)) : null;
                @endphp
                <article class="cart-item">
                    <a href="{{ $product->url() }}"><img src="{{ $product->imageUrl() }}" alt="{{ $product->name }}" loading="lazy" decoding="async"></a>
                    <div class="cart-item-info">
                        <a href="{{ $product->url() }}"><strong>{{ $product->name }}</strong></a>
                        <span class="cart-item-price">
                            @if($hasDiscount)<del>{{ number_format($product->price, 0, ',', ' ') }} ₴</del>@endif
                            <strong class="{{ $hasDiscount ? 'sale-price' : '' }}">{{ number_format($salePrice, 0, ',', ' ') }} ₴</strong>
                            @if($hasDiscount)<em>−{{ $discountPercent }}%</em>@endif
                            <small>/ {{ __('шт.') }}</small>
                        </span>
                    </div>
                    <form class="quantity-form" action="{{ localized_route('cart.update', $product) }}" method="POST" data-cart-form data-cart-quantity-form>@csrf @method('PATCH')<button type="button" data-quantity-change="-1">−</button><input type="number" name="quantity" min="0" max="{{ $product->stock }}" value="{{ $item['quantity'] }}"><button type="button" data-quantity-change="1">+</button></form>
                    <b>{{ number_format($item['subtotal'], 0, ',', ' ') }} ₴</b>
                    <form action="{{ localized_route('cart.destroy', $product) }}" method="POST" data-cart-form>@csrf @method('DELETE')<button type="submit" class="remove-button" data-cart-remove aria-label="{{ __('Видалити') }}">×</button></form>
                </article>
            @endforeach
        </div>
        @php
            $regularTotal = $items->sum(fn ($item) => $item['product']->price * $item['quantity']);
            $savings = max(0, $regularTotal - $total);
        @endphp
        <aside class="cart-summary">
            <h2>{{ __('Разом') }}</h2>
            <div class="cart-summary-row"><span>{{ __('Товари') }}</span><strong>{{ number_format($total, 0, ',', ' ') }} ₴</strong></div>
            @if($savings > 0)<div class="cart-summary-row cart-saving"><span>{{ __('Ваша вигода') }}</span><strong>{{ number_format($savings, 0, ',', ' ') }} ₴</strong></div>@endif
            @include('store._cart-delivery-summary', ['total' => $total])
            <a class="primary-button" href="{{ localized_route('checkout.create') }}">{{ __('Оформити замовлення') }}</a>
        </aside>
    </div>
@endif
