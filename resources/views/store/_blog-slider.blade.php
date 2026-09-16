@if($posts->isNotEmpty())
    <section class="section blog-slider-section" data-product-slider>
        <div class="section-head">
            <h2>{{ $title }}</h2>
            <div class="slider-actions">
                <a class="section-link" href="{{ localized_route('blog.index') }}">{{ __('Всі статті') }}</a>
                <button type="button" data-slider-prev>←</button>
                <button type="button" data-slider-next>→</button>
            </div>
        </div>
        <div class="blog-slider" data-slider-track>
            @foreach($posts as $post)
                <article class="blog-card">
                    <a class="blog-card-image" href="{{ $post->url() }}" aria-hidden="true" tabindex="-1">
                        <img src="{{ $post->cardImageUrl() }}" alt="" width="640" height="400" loading="lazy" decoding="async">
                    </a>
                    <div class="blog-card-body">
                        @if($post->is_featured)
                            <span class="blog-card-badge">{{ __('Рекомендуємо') }}</span>
                        @endif
                        <time class="blog-card-date" datetime="{{ $post->published_at?->toDateString() }}">{{ $post->published_at?->format('d.m.Y') }}</time>
                        <h2><a href="{{ $post->url() }}">{{ $post->title }}</a></h2>
                        @if(filled($post->short_description))
                            <p>{{ $post->short_description }}</p>
                        @endif
                        <a class="blog-card-link" href="{{ $post->url() }}">{{ __('Читати') }} →</a>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
@endif
