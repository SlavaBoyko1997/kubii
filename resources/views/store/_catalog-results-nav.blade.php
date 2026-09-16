{{-- kubii:url-fix:v20 --}}
<div class="catalog-load-more">
    @if ($products->nextPageUrl())
        <a class="primary-button" href="{{ isset($relativeUrl) ? $relativeUrl($products->nextPageUrl()) : browser_url($products->nextPageUrl()) }}" data-load-more>{{ __('Показати ще :count товарів', ['count' => $perPage]) }}</a>
    @endif
</div>
@include('store._catalog-pagination')
@include('store._catalog-seo')
