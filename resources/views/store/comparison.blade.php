@extends('layouts.store')

@section('title', __('Порівняння товарів'))

@section('content')
    <section class="container list-page" data-list-page>
        <div class="section-heading">
            <div>
                <span class="eyebrow">{{ __('Зручний вибір') }}</span>
                <h1>{{ __('Порівняння товарів') }}</h1>
            </div>
            <a class="text-link" href="{{ $catalogEntryUrl }}">{{ __('Додати товари') }} →</a>
        </div>

        @if($products->isEmpty())
            <div class="empty-state">
                <h2>{{ __('Список порівняння порожній') }}</h2>
                <p>{{ __('Додавайте товари кнопкою зі стрілками, щоб зіставити основні характеристики.') }}</p>
                <a class="primary-button" href="{{ $catalogEntryUrl }}">{{ __('Переглянути каталог') }}</a>
            </div>
        @else
            <div class="comparison-grid">
                @foreach($products as $product)
                    <article class="comparison-card" data-list-item>
                        @include('store._product-card', ['product' => $product])
                        <dl>
                            <div><dt>{{ __('Категорія') }}</dt><dd>{{ $product->category?->name ?? '—' }}</dd></div>
                            <div><dt>{{ __('Бренд') }}</dt><dd>{{ $product->brand ?: '—' }}</dd></div>
                            <div><dt>{{ __('Модель') }}</dt><dd>{{ $product->model ?: '—' }}</dd></div>
                            <div><dt>{{ __('Сезон') }}</dt><dd>{{ $product->season ?: '—' }}</dd></div>
                            <div><dt>{{ __('Матеріал') }}</dt><dd>{{ $product->material ?: '—' }}</dd></div>
                            <div><dt>{{ __('Наявність') }}</dt><dd>{{ $product->stock > 0 ? __('Є в наявності') : __('Немає') }}</dd></div>
                        </dl>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
