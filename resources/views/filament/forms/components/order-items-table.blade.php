@php
    use App\Filament\Resources\Products\ProductResource;
    use App\Support\OrderPresentation;

    $order->loadMissing(['items.product']);
@endphp

<div class="order-items-panel">
    <style>
        .order-items-panel {
            --order-items-line: rgba(148, 163, 184, .28);
            --order-items-muted: #64748b;
            --order-items-text: #0f172a;
            --order-items-soft: #f8fafc;
            --order-items-accent: #315f45;
            --order-items-accent-soft: #ecf4ef;
            display: grid;
            gap: 12px;
        }

        .dark .order-items-panel {
            --order-items-line: rgba(255, 255, 255, .12);
            --order-items-muted: #94a3b8;
            --order-items-text: #f8fafc;
            --order-items-soft: rgba(255, 255, 255, .04);
            --order-items-accent: #7cb899;
            --order-items-accent-soft: rgba(124, 184, 153, .12);
        }

        .order-items-panel__head {
            display: none;
            grid-template-columns: minmax(0, 1.8fr) .8fr .7fr .5fr .7fr;
            gap: 12px;
            padding: 0 16px 4px;
            color: var(--order-items-muted);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .order-items-panel__list {
            display: grid;
            gap: 10px;
        }

        .order-item-card {
            display: grid;
            grid-template-columns: 72px minmax(0, 1fr);
            gap: 14px;
            padding: 14px;
            border: 1px solid var(--order-items-line);
            border-radius: 14px;
            background: var(--order-items-soft);
        }

        .order-item-card__media {
            display: block;
            overflow: hidden;
            width: 72px;
            height: 72px;
            border: 1px solid var(--order-items-line);
            border-radius: 12px;
            background: #fff;
        }

        .dark .order-item-card__media {
            background: rgba(255, 255, 255, .06);
        }

        .order-item-card__media img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .order-item-card__placeholder {
            display: grid;
            width: 72px;
            height: 72px;
            place-items: center;
            border: 1px dashed var(--order-items-line);
            border-radius: 12px;
            color: var(--order-items-muted);
            font-size: 12px;
            font-weight: 800;
        }

        .order-item-card__body {
            display: grid;
            gap: 10px;
            min-width: 0;
        }

        .order-item-card__top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
        }

        .order-item-card__title {
            margin: 0;
            color: var(--order-items-text);
            font-size: 15px;
            font-weight: 700;
            line-height: 1.35;
        }

        .order-item-card__title a {
            color: inherit;
            text-decoration: none;
        }

        .order-item-card__title a:hover {
            color: var(--order-items-accent);
        }

        .order-item-card__subtotal {
            flex-shrink: 0;
            color: var(--order-items-text);
            font-size: 16px;
            font-weight: 800;
            white-space: nowrap;
        }

        .order-item-card__meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            color: var(--order-items-muted);
            font-size: 12px;
        }

        .order-item-card__meta span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border-radius: 999px;
            background: rgba(148, 163, 184, .12);
        }

        .order-item-card__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .order-item-card__action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 11px;
            border: 1px solid var(--order-items-line);
            border-radius: 999px;
            background: #fff;
            color: var(--order-items-accent);
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            transition: .15s ease;
        }

        .dark .order-item-card__action {
            background: rgba(255, 255, 255, .04);
        }

        .order-item-card__action:hover {
            border-color: var(--order-items-accent);
            background: var(--order-items-accent-soft);
        }

        .order-item-card__action--muted {
            color: var(--order-items-muted);
        }

        .order-item-card__pricing {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
        }

        .order-item-card__price-box {
            padding: 10px 12px;
            border: 1px solid var(--order-items-line);
            border-radius: 10px;
            background: #fff;
        }

        .dark .order-item-card__price-box {
            background: rgba(255, 255, 255, .03);
        }

        .order-item-card__price-box span {
            display: block;
            color: var(--order-items-muted);
            font-size: 11px;
            font-weight: 600;
        }

        .order-item-card__price-box strong {
            display: block;
            margin-top: 4px;
            color: var(--order-items-text);
            font-size: 14px;
            font-weight: 800;
        }

        .order-item-card__note {
            color: #b45309;
            font-size: 12px;
            font-weight: 600;
        }

        .dark .order-item-card__note {
            color: #fbbf24;
        }

        .order-items-panel__empty {
            padding: 28px 16px;
            border: 1px dashed var(--order-items-line);
            border-radius: 14px;
            color: var(--order-items-muted);
            text-align: center;
            font-size: 14px;
        }

        .order-items-panel__total {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 16px 18px;
            border: 1px solid var(--order-items-line);
            border-radius: 14px;
            background: linear-gradient(135deg, var(--order-items-accent-soft), rgba(255, 255, 255, 0));
        }

        .order-items-panel__total span {
            color: var(--order-items-muted);
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .order-items-panel__total strong {
            color: var(--order-items-text);
            font-size: 22px;
            font-weight: 900;
        }

        @media (min-width: 960px) {
            .order-items-panel__head {
                display: grid;
            }

            .order-item-card {
                grid-template-columns: 72px minmax(0, 1fr);
            }

            .order-item-card__pricing {
                grid-template-columns: repeat(3, minmax(90px, 1fr));
                max-width: 360px;
            }
        }
    </style>

    @if ($order->items->isEmpty())
        <div class="order-items-panel__empty">У замовленні немає товарів</div>
    @else
        <div class="order-items-panel__head" aria-hidden="true">
            <div>Товар</div>
            <div>Артикул</div>
            <div>Ціна</div>
            <div>Кількість</div>
            <div style="text-align:right">Сума</div>
        </div>

        <div class="order-items-panel__list">
            @foreach ($order->items as $item)
                @php
                    $product = $item->product;
                    $sku = $product?->sku ?: $product?->external_id;
                @endphp
                <article class="order-item-card">
                    @if ($product)
                        <a href="{{ $product->url() }}" target="_blank" rel="noopener" class="order-item-card__media">
                            <img src="{{ $product->imageUrl() }}" alt="{{ $item->product_name }}">
                        </a>
                    @else
                        <div class="order-item-card__placeholder">FT</div>
                    @endif

                    <div class="order-item-card__body">
                        <div class="order-item-card__top">
                            <h4 class="order-item-card__title">
                                @if ($product)
                                    <a href="{{ ProductResource::getUrl('edit', ['record' => $product]) }}">
                                        {{ $item->product_name }}
                                    </a>
                                @else
                                    {{ $item->product_name }}
                                @endif
                            </h4>
                            <div class="order-item-card__subtotal">{{ OrderPresentation::formatMoney($item->subtotal) }}</div>
                        </div>

                        <div class="order-item-card__meta">
                            @if ($sku)
                                <span>Артикул: {{ $sku }}</span>
                            @endif
                            @if (filled($item->variant_id))
                                <span>Variant: {{ $item->variant_id }}</span>
                            @endif
                            <span>{{ $item->quantity }} шт.</span>
                        </div>

                        @unless ($product)
                            <div class="order-item-card__note">Товар видалено з каталогу</div>
                        @endunless

                        <div class="order-item-card__pricing">
                            <div class="order-item-card__price-box">
                                <span>Ціна</span>
                                <strong>{{ OrderPresentation::formatMoney($item->price) }}</strong>
                            </div>
                            <div class="order-item-card__price-box">
                                <span>Кількість</span>
                                <strong>{{ $item->quantity }}</strong>
                            </div>
                            <div class="order-item-card__price-box">
                                <span>Сума</span>
                                <strong>{{ OrderPresentation::formatMoney($item->subtotal) }}</strong>
                            </div>
                        </div>

                        @if ($product)
                            <div class="order-item-card__actions">
                                <a href="{{ $product->url() }}" target="_blank" rel="noopener" class="order-item-card__action">
                                    Відкрити на сайті ↗
                                </a>
                                <a href="{{ ProductResource::getUrl('edit', ['record' => $product]) }}" class="order-item-card__action">
                                    Редагувати в адмінці
                                </a>
                            </div>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>

        <div class="order-items-panel__total">
            <span>Разом по замовленню</span>
            <strong>{{ OrderPresentation::formatMoney($order->total) }}</strong>
        </div>
    @endif
</div>
