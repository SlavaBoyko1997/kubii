@extends('layouts.store')

@section('title', __('Обрані товари'))

@section('content')
    <section class="container list-page" data-list-page>
        <div class="section-heading">
            <div>
                <span class="eyebrow">{{ __('Ваш список') }}</span>
                <h1>{{ __('Обрані товари') }}</h1>
            </div>
            <a class="text-link" href="{{ $catalogEntryUrl }}">{{ __('Перейти до каталогу') }} →</a>
        </div>

        @if($products->isEmpty())
            <div class="empty-state">
                <h2>{{ __('В обраному поки порожньо') }}</h2>
                <p>{{ __('Натискайте сердечко на товарах, щоб зберегти їх для наступного перегляду.') }}</p>
                <a class="primary-button" href="{{ $catalogEntryUrl }}">{{ __('Переглянути каталог') }}</a>
            </div>
        @else
            <div class="product-grid">
                @foreach($products as $product)
                    <div data-list-item>
                        @include('store._product-card', ['product' => $product])
                    </div>
                @endforeach
            </div>
        @endif
    </section>
@endsection
