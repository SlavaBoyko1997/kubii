@php
    $paginator = $paginator ?? $products;
    $ariaLabel = $ariaLabel ?? __('Сторінки каталогу');
    $itemsLabel = $itemsLabel ?? __('Показано :from-:to з :total товарів');
    $paginationDataAttribute = $paginationDataAttribute ?? true;
@endphp
@if($paginator->hasPages())
    @php
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();
        $pages = collect([1, $last, ...range(max(1, $current - 2), min($last, $current + 2))])
            ->unique()
            ->sort()
            ->values();
    @endphp
    <nav class="catalog-pagination" @if($paginationDataAttribute) data-catalog-pagination @endif aria-label="{{ $ariaLabel }}">
        <div class="pagination-meta">
            <strong>{{ __('Сторінка :current з :last', ['current' => $current, 'last' => $last]) }}</strong>
            <span>{{ __($itemsLabel, ['from' => $paginator->firstItem(), 'to' => $paginator->lastItem(), 'total' => $paginator->total()]) }}</span>
        </div>
        <div class="pagination-pages">
            @if($paginator->onFirstPage())<span class="pagination-step is-disabled">←</span>@else<a class="pagination-step" href="{{ isset($relativeUrl) ? $relativeUrl($paginator->previousPageUrl()) : browser_url($paginator->previousPageUrl()) }}" rel="prev" aria-label="{{ __('Попередня сторінка') }}">←</a>@endif
            @foreach($pages as $index => $page)
                @if($index > 0 && $page - $pages[$index - 1] > 1)<span class="pagination-dots">…</span>@endif
                @if($page === $current)
                    <span class="pagination-number is-current" aria-current="page">{{ $page }}</span>
                @else
                    <a class="pagination-number" href="{{ isset($relativeUrl) ? $relativeUrl($paginator->url($page)) : browser_url($paginator->url($page)) }}" aria-label="{{ __('Сторінка :page', ['page' => $page]) }}">{{ $page }}</a>
                @endif
            @endforeach
            @if($paginator->hasMorePages())<a class="pagination-step" href="{{ isset($relativeUrl) ? $relativeUrl($paginator->nextPageUrl()) : browser_url($paginator->nextPageUrl()) }}" rel="next" aria-label="{{ __('Наступна сторінка') }}">→</a>@else<span class="pagination-step is-disabled">→</span>@endif
        </div>
    </nav>
@endif
