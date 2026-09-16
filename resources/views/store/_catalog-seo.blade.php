<section class="catalog-seo" data-catalog-seo>
    <h2>{{ $catalogHeading ?? ($currentCategory?->pageH1() ?? __('Туристичні та рибальські товари')) }}</h2>
    @php($categorySeoText = $currentCategory ? trim((string) $currentCategory->translated('description')) : '')
    @php($categoryIntro = $currentCategory ? trim((string) $currentCategory->translated('seo_intro')) : '')
    @if($currentCategory && ! $hasActiveFilters && $categorySeoText !== '')
        @if($categoryIntro !== '')
            <p>{{ $categoryIntro }}</p>
        @endif
        {!! $categorySeoText !!}
    @else
        <p>{{ __(':heading: добірка спорядження для туризму, кемпінгу та риболовлі. Порівнюйте характеристики, бренди, ціни й доступність товарів перед замовленням.', ['heading' => $catalogHeading ?? ($currentCategory?->name ?? 'Каталог Kubii')]) }}</p>
    @endif
    @if($hasActiveFilters)
        <p>{{ __('Поточний відбір: :filters. Сторінка сформована з урахуванням вибраних параметрів, тому користувачі й пошукові системи бачать релевантний перелік товарів.', ['filters' => implode(', ', $activeFilterLabels)]) }}</p>
    @endif
    @if($currentCategory && ($categorySeoText === '' || $hasActiveFilters))
        <p>{{ __('У категорії «:category» доступно :count товарів. Використовуйте фільтри за брендом, моделлю, сезоном, матеріалом і ціною, щоб швидко знайти відповідний варіант.', ['category' => $currentCategory->name, 'count' => $products->total()]) }}</p>
    @endif
</section>
