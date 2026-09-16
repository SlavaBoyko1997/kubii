@extends('layouts.store')

@section('title', $product->seoTitle())

@push('head')
    @unless($product->is_indexable)
        <meta name="robots" content="noindex, follow">
    @endunless
    <x-seo-schema :schema="$seoSchema" />
@endpush

@push('analytics')
    <x-google-analytics-event name="view_item" :payload="\App\Support\GoogleAnalytics::viewItemPayload($product)" />
@endpush

@section('content')
    <div class="container page-space">
        <div class="breadcrumbs"><a href="{{ localized_route('home') }}">{{ __('Головна') }}</a> @foreach($product->category->breadcrumbTrail() as $crumb) / <a href="{{ $crumb->catalogUrl() }}">{{ $crumb->name }}</a> @endforeach / <span>{{ $product->name }}</span></div>
        <section class="product-detail">
            @php
                $galleryImages = $product->gallery();
                $hasMultipleImages = count($galleryImages) > 1;
            @endphp
            <div class="product-gallery" data-product-gallery>
                <div class="product-detail-image {{ $hasMultipleImages ? 'has-multiple-images' : '' }}">@if($product->discount_percent && ! $product->isPricePending())<span class="discount-label">−{{ $product->discount_percent }}%</span>@endif @if($product->isPricePending())<span class="stock-label">{{ __('Очікується надходження') }}</span>@elseif($product->stock === 1)<span class="low-stock-label">{{ __('Скоро закінчується') }}</span>@endif<img src="{{ $galleryImages[0] ?? '' }}" alt="{{ $product->name }}" width="900" height="900" fetchpriority="high" decoding="async" data-gallery-main data-open-image-lightbox tabindex="0" role="button" aria-label="{{ __('Збільшити фото') }}"><span class="image-zoom-hint" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 7.5v6M7.5 10.5h6"/></svg></span> @if($hasMultipleImages)<button class="gallery-arrow gallery-arrow-prev" type="button" data-gallery-prev aria-label="{{ __('Попереднє фото') }}">‹</button><button class="gallery-arrow gallery-arrow-next" type="button" data-gallery-next aria-label="{{ __('Наступне фото') }}">›</button><span class="gallery-counter"><b data-gallery-current>1</b> / {{ count($galleryImages) }}</span>@if(count($galleryImages) <= 8)<span class="gallery-dots" data-gallery-dots aria-hidden="true">@foreach($galleryImages as $image)<span class="{{ $loop->first ? 'is-active' : '' }}"></span>@endforeach</span>@endif@endif</div>
                @if($hasMultipleImages)<div class="gallery-thumbs" data-gallery-thumbs>@foreach($galleryImages as $image)<button class="{{ $loop->first ? 'is-active' : '' }}" type="button" data-gallery-thumb="{{ $image }}" data-gallery-index="{{ $loop->index }}"><img src="{{ $image }}" alt="{{ __(':product, фото :number', ['product' => $product->name, 'number' => $loop->iteration]) }}" width="160" height="160" loading="lazy" decoding="async"></button>@endforeach</div>@endif
            </div>
            <div class="product-summary">
                <span class="product-category">{{ $product->category->name }}</span>
                <h1>{{ $product->pageH1() }}</h1>
                <div class="detail-rating">@include('store._rating', ['rating' => $product->visible_reviews_avg_rating]) <strong>{{ number_format((float) $product->visible_reviews_avg_rating, 1, ',', ' ') }}</strong><a href="#reviews">{{ __('Відгуків: :count', ['count' => $product->visible_reviews_count]) }}</a></div>
                <div class="product-meta"><span>{{ __('Код товару:') }} <strong>{{ $product->sku }}</strong></span>@if($product->brand)<span>{{ __('Бренд:') }} @if($product->brandUrl())<a href="{{ $product->brandUrl(false) }}"><strong>{{ $product->brand }}</strong></a>@else<strong>{{ $product->brand }}</strong>@endif</span>@endif</div>
                @if($showAdminProductTools ?? false)
                    <div class="product-admin-tools">
                        @if($adminDownloadImagesUrl)
                            <a class="product-admin-tool" href="{{ $adminDownloadImagesUrl }}">{{ __('Скачати фото') }}</a>
                        @endif
                        @if($copyableDetailsText)
                            <button class="product-admin-tool" type="button" data-copy-product-specs data-copy-text='@json($copyableDetailsText)'>{{ __('Скопіювати характеристики') }}</button>
                        @endif
                    </div>
                @endif
                @if($variantGroup && $variantGroup->products->count() > 1)
                    @php
                        $variantProducts = $variantGroup->products;
                        $variantCountLabel = \App\Models\Product::variantCountLabel($variantGroup->products->count());
                        $variantOptionKeys = collect($variantGroup->variant_option_keys ?? [])
                            ->filter(fn (string $optionKey): bool => $variantProducts
                                ->map(fn ($variant) => data_get($variant->variant_options, $optionKey))
                                ->filter()
                                ->unique()
                                ->isNotEmpty())
                            ->values();
                        $variantOptionValues = $variantOptionKeys
                            ->mapWithKeys(fn (string $optionKey): array => [$optionKey => $variantProducts
                                ->map(fn ($variant) => data_get($variant->variant_options, $optionKey))
                                ->filter()
                                ->unique()
                                ->values()]);
                        $visibleVariantOptionKeys = collect();

                        foreach ($variantOptionKeys as $optionKey) {
                            $values = $variantOptionValues[$optionKey] ?? collect();
                            $duplicatesVisibleOption = $visibleVariantOptionKeys->contains(function (string $visibleKey) use ($variantProducts, $variantOptionValues, $optionKey, $values): bool {
                                $visibleValues = $variantOptionValues[$visibleKey] ?? collect();

                                if ($visibleValues->count() !== $values->count()) {
                                    return false;
                                }

                                $leftToRight = [];
                                $rightToLeft = [];

                                foreach ($variantProducts as $variant) {
                                    $left = data_get($variant->variant_options, $visibleKey);
                                    $right = data_get($variant->variant_options, $optionKey);

                                    if (! filled($left) || ! filled($right)) {
                                        return false;
                                    }

                                    if ((isset($leftToRight[$left]) && $leftToRight[$left] !== $right) || (isset($rightToLeft[$right]) && $rightToLeft[$right] !== $left)) {
                                        return false;
                                    }

                                    $leftToRight[$left] = $right;
                                    $rightToLeft[$right] = $left;
                                }

                                return count($leftToRight) === $visibleValues->count() && count($rightToLeft) === $values->count();
                            });

                            if (! $duplicatesVisibleOption) {
                                $visibleVariantOptionKeys->push($optionKey);
                            }
                        }
                    @endphp
                    <div class="variant-picker">
                        <div class="variant-picker-head">
                            <div>
                                <span>{{ __('Варіанти товару') }}</span>
                                <strong>{{ $variantGroup->title }}</strong>
                            </div>
                            <em>{{ $variantCountLabel }}</em>
                        </div>
                        @foreach($visibleVariantOptionKeys as $optionKey)
                            @php
                                $values = $variantOptionValues[$optionKey] ?? collect();
                            @endphp
                            @if($values->isNotEmpty())
                                @php
                                    $isColorOption = app(\App\Support\ProductColorVariants::class)->isColorOptionKey($optionKey);
                                    $values = app(\App\Support\ProductColorVariants::class)->sortOptionValues($variantProducts, $optionKey, $values);
                                @endphp
                                <div class="variant-option-row">
                                    <div class="variant-option-label">
                                        <span>{{ app(\App\Support\ProductColorVariants::class)->translateOptionKey($optionKey) }}</span>
                                        @if(data_get($product->variant_options, $optionKey))
                                            <small>{{ __('Обрано:') }} {{ data_get($product->variant_options, $optionKey) }}</small>
                                        @endif
                                    </div>
                                    <div @class(['variant-option-values', 'is-color-swatches' => $isColorOption])>
                                        @foreach($values as $value)
                                            @php
                                                $target = $variantProducts->first(function ($variant) use ($product, $optionKey, $value, $visibleVariantOptionKeys) {
                                                    $options = $product->variant_options ?? [];
                                                    $options[$optionKey] = $value;

                                                    foreach (collect($options)->only($visibleVariantOptionKeys->all()) as $key => $selectedValue) {
                                                        if (filled($selectedValue) && data_get($variant->variant_options, $key) !== $selectedValue) {
                                                            return false;
                                                        }
                                                    }

                                                    return true;
                                                }) ?: $variantProducts->first(fn ($variant) => data_get($variant->variant_options, $optionKey) === $value);
                                            @endphp
                                            @if($target)
                                                @if($isColorOption)
                                                    <a class="product-color-swatch {{ $target->is($product) ? 'is-current' : '' }} {{ $target->isPurchasable() ? '' : 'is-unavailable' }}" href="{{ $target->url() }}" title="{{ $target->isPurchasable() ? $value : $value.' — '.__('Немає в наявності') }}" @if($target->is($product)) aria-current="page" @endif>
                                                        <img src="{{ $target->imageUrl() }}" alt="{{ $value }}" loading="lazy" decoding="async">
                                                    </a>
                                                @else
                                                    <a class="variant-chip {{ $target->is($product) ? 'is-current' : '' }} {{ $target->isPurchasable() ? '' : 'is-unavailable' }}" href="{{ $target->url() }}" title="{{ $target->isPurchasable() ? $target->name : $value.' — '.__('Немає в наявності') }}" @if($target->is($product)) aria-current="page" @endif>
                                                        <span>{{ $value }}</span>
                                                    </a>
                                                @endif
                                            @else
                                                @if($isColorOption)
                                                    <span class="product-color-swatch is-unavailable" title="{{ $value }} — {{ __('Немає в наявності') }}">
                                                        <span>{{ \Illuminate\Support\Str::substr($value, 0, 1) }}</span>
                                                    </span>
                                                @else
                                                    <span class="variant-chip is-unavailable" title="{{ $value }} — {{ __('Немає в наявності') }}"><span>{{ $value }}</span></span>
                                                @endif
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach
                        <div class="variant-picker-selected">
                            <span>{{ __('Поточний вибір') }}</span>
                            <strong>{{ $product->variantLabel($visibleVariantOptionKeys->all()) }}</strong>
                        </div>
                    </div>
                @endif
                <div class="price-panel">
                    <div class="detail-price {{ $product->discount_percent && ! $product->isPricePending() ? 'has-discount' : '' }}">@if($product->isPricePending())<strong class="price-pending">{{ __('Ціну ще не розраховано') }}</strong>@else @if($product->discount_percent)<del>{{ number_format($product->price, 0, ',', ' ') }} ₴</del>@endif<strong>{{ number_format($product->salePrice(), 0, ',', ' ') }} ₴</strong>@endif</div>
                    <p class="stock {{ $product->isPurchasable() ? '' : 'out' }}">{{ $product->isPricePending() ? __('Очікується надходження') : ($product->stock ? __('Є в наявності') : __('Немає в наявності')) }}</p>
                    <div class="detail-actions">
                        @if($product->isPurchasable())
                            <form class="add-form" action="{{ localized_route('cart.store', $product) }}" method="POST" data-cart-form data-cart-product="{{ $product->id }}" data-analytics-item='@json(\App\Support\GoogleAnalytics::productItem($product))'>
                                @csrf
                                <input type="number" name="quantity" min="1" max="{{ $product->stock }}" value="1" aria-label="{{ __('Кількість') }}">
                                <button class="primary-button {{ in_array($product->id, $cartProductIds) ? 'in-cart' : '' }}" data-cart-button data-cart-default-label="{{ __('Додати до кошика') }}" data-cart-active-label="{{ __('У кошику') }}">{{ in_array($product->id, $cartProductIds) ? __('У кошику') : __('Додати до кошика') }}</button>
                            </form>
                            <button class="quick-order-button" type="button" data-open-quick-order data-product-id="{{ $product->id }}" data-product-name="{{ $product->name }}">{{ __('Швидке замовлення в один клік') }}</button>
                        @endif
                        @include('store._product-list-actions', ['product' => $product, 'class' => 'detail-list-actions'])
                    </div>
                </div>
                @include('store._product-delivery-payment', [
                    'shipmentProfile' => $shipmentProfile,
                    'paymentOptions' => $paymentOptions,
                ])
            </div>
        </section>
        <section class="product-information">
            <article class="product-info-card" data-expandable-section>
                <h2>{{ __('Коротка інформація') }}</h2>
                <div class="product-info-content">
                    @forelse($product->descriptionParagraphs() as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @empty
                        <p>{{ $product->plainDescription() }}</p>
                    @endforelse
                    <p>{{ __('Товар підібраний для активного використання у подорожах і на природі. Перед замовленням перевірте характеристики та доступний залишок.') }}</p>
                </div>
                <button class="show-more-button" type="button" data-expand-section>{{ __('Показати ще') }}</button>
            </article>
            <article class="product-info-card" data-expandable-section>
                <h2>{{ __('Характеристики') }}</h2>
                <div class="product-info-content">
                    <dl>@forelse(($orderedSpecifications ?? $product->orderedDisplaySpecifications()) as $name => $value)<div><dt>{{ $name }}</dt><dd><a href="{{ $product->filterUrl($name, $value) }}">{{ $value }}</a></dd></div>@empty<div><dt>{{ __('Категорія') }}</dt><dd><a href="{{ $product->category->catalogUrl() }}">{{ $product->category->name }}</a></dd></div>@endforelse</dl>
                </div>
                <button class="show-more-button" type="button" data-expand-section>{{ __('Показати ще') }}</button>
            </article>
        </section>
        @if($variantGroup && $variantGroup->products->count() > 1)
            @php
                $modelRangeOptionKeys = isset($visibleVariantOptionKeys)
                    ? $visibleVariantOptionKeys->all()
                    : ($variantGroup->variant_option_keys ?? []);
                $modelRangeProducts = $variantGroup->products
                    ->sortBy(fn ($modelProduct) => $modelProduct->variantLabel($modelRangeOptionKeys), SORT_NATURAL)
                    ->values();
            @endphp
            <section class="model-range">
                <div class="model-range-head">
                    <div>
                        <span class="auth-kicker">{{ __('Модельний ряд') }}</span>
                        <h2>{{ $variantGroup->title }}</h2>
                    </div>
                    <strong>{{ \App\Models\Product::variantCountLabel($modelRangeProducts->count()) }}</strong>
                </div>
                <div class="model-range-grid">
                    @foreach($modelRangeProducts as $modelProduct)
                        <article class="model-range-card {{ $modelProduct->is($product) ? 'is-current' : '' }} {{ $modelProduct->isPurchasable() ? '' : 'is-unavailable' }}" @if($loop->iteration > 4) hidden data-model-range-extra @endif>
                            <a class="model-range-image" href="{{ $modelProduct->url() }}">
                                <img src="{{ $modelProduct->imageUrl() }}" alt="{{ $modelProduct->name }}" loading="lazy" decoding="async">
                                @if($modelProduct->is($product))<span>{{ __('Зараз відкрито') }}</span>@endif
                            </a>
                            <div class="model-range-body">
                                <a href="{{ $modelProduct->url() }}">{{ $modelProduct->name }}</a>
                                <p>{{ $modelProduct->variantLabel($modelRangeOptionKeys) }}</p>
                                <small>{{ __('Код:') }} {{ $modelProduct->sku }}</small>
                            </div>
                            <div class="model-range-side">
                                <div class="model-range-price">
                                    @if($modelProduct->isPricePending())
                                        <strong class="price-pending">{{ __('Ціну ще не розраховано') }}</strong>
                                    @else
                                        @if($modelProduct->discount_percent)<del>{{ number_format($modelProduct->price, 0, ',', ' ') }} ₴</del>@endif
                                        <strong>{{ number_format($modelProduct->salePrice(), 0, ',', ' ') }} ₴</strong>
                                    @endif
                                </div>
                                @if($modelProduct->isPurchasable())
                                    <form action="{{ localized_route('cart.store', $modelProduct) }}" method="POST" data-cart-form data-cart-product="{{ $modelProduct->id }}" data-analytics-item='@json(\App\Support\GoogleAnalytics::productItem($modelProduct))'>
                                        @csrf
                                        <input type="hidden" name="quantity" value="1">
                                        <button class="buy-button {{ in_array($modelProduct->id, $cartProductIds) ? 'in-cart' : '' }}" data-cart-button data-cart-default-label="{{ __('Купити') }}" data-cart-active-label="{{ __('У кошику') }}">{{ in_array($modelProduct->id, $cartProductIds) ? __('У кошику') : __('Купити') }}</button>
                                    </form>
                                @else
                                    <a class="model-range-link" href="{{ $modelProduct->url() }}">{{ $modelProduct->isPricePending() ? __('Очікується') : __('Перейти') }}</a>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
                @if($modelRangeProducts->count() > 4)
                    <div class="model-range-more"><button class="show-more-button" type="button" data-show-model-range>{{ __('Показати ще') }} {{ $modelRangeProducts->count() - 4 }}</button></div>
                @endif
            </section>
        @endif
        <section class="reviews-section" id="reviews">
            <div class="reviews-head"><div><span class="auth-kicker">{{ __('Думка покупців') }}</span><h2>{{ __('Відгуки та рейтинг') }}</h2></div><div class="review-score"><strong>{{ number_format((float) $product->visible_reviews_avg_rating, 1, ',', ' ') }}</strong>@include('store._rating', ['rating' => $product->visible_reviews_avg_rating])<span>{{ __('Відгуків: :count', ['count' => $product->visible_reviews_count]) }}</span></div></div>
            <div class="reviews-layout">
                <div class="reviews-list">
                    @forelse($reviews as $review)
                        <article class="review-card">
                            <div><span class="review-author"><strong>{{ $review->user->fullName() }}</strong>@if($review->is_verified_purchase)<i>✓ {{ __('Товар куплений') }}</i>@endif</span><time>{{ ($review->review_date ?? $review->created_at)->format('d.m.Y H:i') }}</time></div>
                            @include('store._rating', ['rating' => $review->rating])
                            @if($review->title)<h3>{{ $review->title }}</h3>@endif
                            <p>{{ $review->body }}</p>
                            @if($review->pros)<p class="review-detail pros"><strong>{{ __('Переваги:') }}</strong> {{ $review->pros }}</p>@endif
                            @if($review->cons)<p class="review-detail cons"><strong>{{ __('Недоліки:') }}</strong> {{ $review->cons }}</p>@endif
                            <div class="review-actions">
                                <button type="button" data-toggle-review-reply>{{ __('Відповісти') }}</button>
                                <div class="review-votes">
                                    @auth
                                        <form action="{{ localized_route('reviews.vote', $review) }}" method="POST">@csrf<input type="hidden" name="is_helpful" value="1"><button title="{{ __('Корисний відгук') }}">👍 {{ $review->helpful_votes_count }}</button></form>
                                        <form action="{{ localized_route('reviews.vote', $review) }}" method="POST">@csrf<input type="hidden" name="is_helpful" value="0"><button title="{{ __('Некорисний відгук') }}">👎 {{ $review->unhelpful_votes_count }}</button></form>
                                    @else
                                        <span>👍 {{ $review->helpful_votes_count }}</span><span>👎 {{ $review->unhelpful_votes_count }}</span>
                                    @endauth
                                </div>
                            </div>
                            @if($review->replies->isNotEmpty())
                                <div class="review-replies">
                                    @foreach($review->replies as $reply)
                                        <div class="review-reply {{ $reply->user->is_admin ? 'from-store' : '' }}"><div><strong>{{ $reply->user->fullName() }} @if($reply->user->is_admin)<i>{{ __('Представник магазину') }}</i>@endif</strong><time>{{ $reply->created_at->format('d.m.Y H:i') }}</time></div><p>{{ $reply->body }}</p></div>
                                    @endforeach
                                </div>
                            @endif
                            @auth
                                <form class="review-reply-form" action="{{ localized_route('reviews.replies.store', $review) }}" method="POST" hidden>@csrf<input name="body" minlength="3" maxlength="1000" required placeholder="{{ __('Написати відповідь...') }}"><button type="submit">{{ __('Надіслати') }}</button></form>
                            @endauth
                        </article>
                    @empty
                        <div class="empty-state"><h3>{{ __('Відгуків ще немає') }}</h3><p>{{ __('Поділіться першим враженням про цей товар.') }}</p></div>
                    @endforelse
                    @if($reviews->hasPages())
                        <nav class="review-pagination" aria-label="{{ __('Сторінки відгуків') }}">
                            @if($reviews->onFirstPage())<span>← {{ __('Попередня') }}</span>@else<a href="{{ $reviews->fragment('reviews')->previousPageUrl() }}">← {{ __('Попередня') }}</a>@endif
                            <strong>{{ $reviews->currentPage() }} / {{ $reviews->lastPage() }}</strong>
                            @if($reviews->hasMorePages())<a href="{{ $reviews->fragment('reviews')->nextPageUrl() }}">{{ __('Наступна') }} →</a>@else<span>{{ __('Наступна') }} →</span>@endif
                        </nav>
                    @endif
                </div>
                <aside class="review-form-card">
                    <h3>{{ __('Залишити відгук') }}</h3>
                    @auth
                        <form action="{{ localized_route('reviews.store', $product) }}" method="POST">@csrf
                            <fieldset class="star-input"><legend>{{ __('Ваша оцінка') }}</legend>@for($star = 5; $star >= 1; $star--)<input id="star-{{ $star }}" type="radio" name="rating" value="{{ $star }}" required><label for="star-{{ $star }}" title="{{ __(':star з 5', ['star' => $star]) }}">★</label>@endfor</fieldset>
                            <label>{{ __('Заголовок') }}<input name="title" maxlength="120" placeholder="{{ __('Коротко про враження') }}"></label>
                            <label>{{ __('Ваш відгук') }}<textarea name="body" rows="5" minlength="10" maxlength="1500" required placeholder="{{ __('Що вам сподобалося або варто врахувати?') }}"></textarea></label>
                            <label>{{ __('Переваги') }}<textarea name="pros" rows="2" maxlength="1000" placeholder="{{ __('Що особливо сподобалося?') }}"></textarea></label>
                            <label>{{ __('Недоліки') }}<textarea name="cons" rows="2" maxlength="1000" placeholder="{{ __('Що варто покращити?') }}"></textarea></label>
                            @if ($errors->any())<div class="validation-errors">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
                            <button class="primary-button">{{ __('Опублікувати відгук') }}</button>
                        </form>
                    @else
                        <p>{{ __('Увійдіть до кабінету, щоб поставити оцінку та написати відгук.') }}</p><button class="primary-button" type="button" data-open-auth>{{ __('Увійти') }}</button>
                    @endauth
                </aside>
            </div>
        </section>
        @if ($relatedProducts->isNotEmpty())
            @include('store._product-slider', ['title' => __('Схожі товари'), 'products' => $relatedProducts])
        @endif
    </div>
@endsection
