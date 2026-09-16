<div class="mega-roots">
    @foreach($menuCategories as $index => $category)
        <button type="button" class="{{ $index === 0 ? 'is-current' : '' }}" data-mega-root-mobile="{{ $category['id'] }}">{{ $category['name'] }}</button>
    @endforeach
</div>

<div class="mega-desktop">
    @foreach($menuCategories as $index => $category)
        @php($branches = $category['all_children'] ?? $category['children'] ?? [])
        <div class="mega-panel {{ $index === 0 ? 'is-active' : '' }}" data-mega-panel="{{ $category['id'] }}">
            <div class="mega-panel-inner">
                <div class="mega-columns">
                    @forelse($branches as $child)
                        @php($leaves = array_values($child['all_children'] ?? $child['children'] ?? []))
                        @php($visibleLeaves = array_slice($leaves, 0, 8))
                        @php($hiddenLeaves = array_slice($leaves, 8))
                        <div class="mega-column">
                            <a class="mega-column-title" href="{{ $child['url'] ?? '#' }}">{{ $child['name'] }}</a>
                            @foreach($visibleLeaves as $leaf)
                                <a href="{{ $leaf['url'] ?? '#' }}">{{ $leaf['name'] }}</a>
                            @endforeach
                            @if($hiddenLeaves !== [])
                                <div class="mega-extra" hidden>
                                    @foreach($hiddenLeaves as $leaf)
                                        <a href="{{ $leaf['url'] ?? '#' }}">{{ $leaf['name'] }}</a>
                                    @endforeach
                                </div>
                                <button class="mega-more" type="button" data-mega-more>{{ __('Показати ще') }}</button>
                            @endif
                        </div>
                    @empty
                        <div class="mega-column">
                            <a class="mega-column-title" href="{{ $category['url'] ?? '#' }}">{{ $category['name'] }}</a>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @endforeach
</div>

@if(($menuBrands ?? []) !== [])
    <div class="mega-brands" data-mega-brands>
        <div class="mega-brands-track">
            @foreach([0, 1] as $loopCopy)
                <a class="mega-brands-all" href="{{ localized_route('brands.index') }}">{{ __('Усі бренди') }}</a>
                @foreach($menuBrands as $brand)
                    <a href="{{ localized_route('brands.show', $brand['slug']) }}">{{ $brand['name'] }}</a>
                @endforeach
            @endforeach
        </div>
    </div>
@endif
