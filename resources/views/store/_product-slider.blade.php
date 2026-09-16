@if($products->isNotEmpty())
    <section class="section product-slider-section" data-product-slider>
        <div class="section-head"><h2>{{ $title }}</h2><div class="slider-actions"><button type="button" data-slider-prev>←</button><button type="button" data-slider-next>→</button></div></div>
        <div class="product-slider" data-slider-track>@foreach($products as $product) @include('store._product-card') @endforeach</div>
    </section>
@endif
