const overlay = document.querySelector('[data-overlay]');
const catalog = document.querySelector('[data-catalog-popup]');
const drawer = document.querySelector('[data-cart-drawer]');
const auth = document.querySelector('[data-auth-popup]');
const quickOrder = document.querySelector('[data-quick-order-popup]');
const toast = document.querySelector('[data-toast]');
const globalLoader = document.querySelector('[data-global-loader]');
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
const filterClickUrl = document.querySelector('meta[name="catalog-filter-click-url"]')?.content;
const catalogMenuUrl = document.querySelector('meta[name="catalog-menu-url"]')?.content;
const searchSuggestionsUrl = document.querySelector('meta[name="search-suggestions-url"]')?.content;
const searchClickUrl = document.querySelector('meta[name="search-click-url"]')?.content;
const t = (key) => window.storeI18n?.[key] ?? key;

document.addEventListener('error', (event) => {
    const image = event.target;
    if (!(image instanceof HTMLImageElement) || !image.dataset.imageFallback || image.dataset.fallbackApplied) return;
    image.dataset.fallbackApplied = 'true';
    image.src = image.dataset.imageFallback;
}, true);

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
})[character]);

const searchPopup = document.querySelector('[data-search-suggestions]');
const searchResults = searchPopup?.querySelector('[data-search-results]');
const searchState = searchPopup?.querySelector('[data-search-state]');
const smartSearchInputs = [...document.querySelectorAll('[data-smart-search-input]')];
let activeSearchInput = null;
let searchTimer = null;
let searchRequest = null;
let activeSearchResult = -1;

const closeSearch = () => {
    if (!searchPopup) return;
    searchPopup.hidden = true;
    activeSearchResult = -1;
};

const searchResultLinks = () => [...(searchPopup?.querySelectorAll('[data-search-result]') ?? [])];

const activateSearchResult = (index) => {
    const links = searchResultLinks();
    if (links.length === 0) return;
    activeSearchResult = (index + links.length) % links.length;
    links.forEach((link, linkIndex) => link.classList.toggle('is-active', linkIndex === activeSearchResult));
    links[activeSearchResult].scrollIntoView({ block: 'nearest' });
};

const searchSection = (title, items, type) => {
    if (!items.length) return '';

    return `
        <div class="search-suggestions-section">
            <span class="search-suggestions-title">${escapeHtml(title)}</span>
            ${items.map((item) => type === 'product' ? `
                <a class="search-suggestion" href="${escapeHtml(item.url)}" data-search-result data-search-type="product" data-search-id="${Number(item.id)}" data-search-query="${escapeHtml(activeSearchInput?.value.trim())}">
                    <img src="${escapeHtml(item.image)}" alt="" width="120" height="120" loading="lazy" decoding="async">
                    <span><strong>${escapeHtml(item.name)}</strong><small>${escapeHtml([item.category, item.brand].filter(Boolean).join(' · '))}</small></span>
                    <b class="${item.stock ? '' : 'out-of-stock-text'}">${escapeHtml(item.price ?? t('Ціна уточнюється'))}</b>
                </a>
            ` : `
                <a class="search-suggestion" href="${escapeHtml(item.url)}" data-search-result data-search-type="category" data-search-id="${Number(item.id)}" data-search-query="${escapeHtml(activeSearchInput?.value.trim())}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3.75 3.75h6.5v6.5h-6.5v-6.5Zm10 0h6.5v6.5h-6.5v-6.5Zm-10 10h6.5v6.5h-6.5v-6.5Zm10 0h6.5v6.5h-6.5v-6.5Z"/></svg>
                    <span><strong>${escapeHtml(item.name)}</strong><small>${escapeHtml(item.path)}</small></span>
                </a>
            `).join('')}
        </div>
    `;
};

const renderSearchSuggestions = (data) => {
    const hasResults = data.categories.length || data.products.length;
    searchState.hidden = hasResults;
    searchState.textContent = hasResults ? '' : t('Нічого точного не знайшли. Спробуйте коротший запит.');
    searchResults.innerHTML = `
        ${data.corrected ? `<div class="search-suggestions-correction">${escapeHtml(t('Розпізнано як:'))} <strong>${escapeHtml(data.corrected)}</strong></div>` : ''}
        ${searchSection(t('Категорії'), data.categories, 'category')}
        ${searchSection(t('Товари'), data.products, 'product')}
        ${hasResults ? `<a class="search-suggestions-all" href="${escapeHtml(data.all_url)}">${escapeHtml(t('Показати всі результати'))}</a>` : ''}
    `;
    activeSearchResult = -1;
};

const requestSearchSuggestions = async (input) => {
    const query = input.value.trim();
    activeSearchInput = input;
    searchPopup.hidden = false;
    searchResults.innerHTML = '';

    if (query.length < 2) {
        searchState.hidden = false;
        searchState.textContent = t('Введіть щонайменше 2 символи');
        return;
    }

    searchState.hidden = false;
    searchState.textContent = t('Шукаємо...');
    searchRequest?.abort();
    searchRequest = new AbortController();

    try {
        const url = new URL(searchSuggestionsUrl, window.location.origin);
        url.searchParams.set('q', query);
        const response = await fetch(url, {
            signal: searchRequest.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error();
        renderSearchSuggestions(await response.json());
    } catch (error) {
        if (error.name === 'AbortError') return;
        searchState.hidden = false;
        searchState.textContent = t('Не вдалося завантажити підказки. Натисніть Enter для пошуку.');
    }
};

smartSearchInputs.forEach((input) => {
    input.addEventListener('focus', () => {
        activeSearchInput = input;
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => requestSearchSuggestions(input), 30);
    });
    input.addEventListener('input', () => {
        smartSearchInputs.filter((candidate) => candidate !== input).forEach((candidate) => {
            candidate.value = input.value;
        });
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => requestSearchSuggestions(input), 140);
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            activateSearchResult(activeSearchResult + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            activateSearchResult(activeSearchResult - 1);
        } else if (event.key === 'Enter' && activeSearchResult >= 0) {
            event.preventDefault();
            searchResultLinks()[activeSearchResult]?.click();
        }
    });
});

document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-smart-search], [data-search-suggestions]')) closeSearch();
});

document.addEventListener('click', (event) => {
    const result = event.target.closest('[data-search-result]');
    if (!result || !searchClickUrl) return;

    const payload = new FormData();
    payload.append('_token', csrfToken ?? '');
    payload.append('query', result.dataset.searchQuery ?? activeSearchInput?.value ?? '');
    payload.append('type', result.dataset.searchType);
    payload.append('id', result.dataset.searchId);

    if (navigator.sendBeacon) {
        navigator.sendBeacon(searchClickUrl, payload);
    } else {
        fetch(searchClickUrl, {
            method: 'POST',
            body: payload,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            keepalive: true,
        }).catch(() => {});
    }
});

const setGlobalLoading = (loading) => {
    globalLoader?.classList.toggle('is-open', loading);
};

const setElementLoading = (element, loading, label = t('Завантажуємо...')) => {
    if (!element) return;
    element.classList.toggle('is-loading', loading);
    element.toggleAttribute('aria-busy', loading);

    const button = element.matches('button, a') ? element : element.querySelector('button[type="submit"], button:not([type]), [data-load-more]');
    if (!button) return;

    if (loading) {
        button.dataset.loadingOriginalLabel ??= button.textContent;
        button.disabled = button instanceof HTMLButtonElement ? true : button.disabled;
        button.classList.add('is-loading');
        button.textContent = label;
    } else {
        button.classList.remove('is-loading');
        if (button.dataset.loadingOriginalLabel) button.textContent = button.dataset.loadingOriginalLabel;
        if (button instanceof HTMLButtonElement) button.disabled = false;
        delete button.dataset.loadingOriginalLabel;
    }
};

document.querySelectorAll('[data-product-slider]').forEach((slider) => {
    const track = slider.querySelector('[data-slider-track]');
    slider.querySelector('[data-slider-prev]')?.addEventListener('click', () => track.scrollBy({ left: -track.clientWidth * .8, behavior: 'smooth' }));
    slider.querySelector('[data-slider-next]')?.addEventListener('click', () => track.scrollBy({ left: track.clientWidth * .8, behavior: 'smooth' }));
});

document.querySelectorAll('[data-showcase-scroll-next]').forEach((button) => {
    const track = button.closest('.showcase-scroll-shell')?.querySelector('.showcase-grid');

    button.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        track?.scrollBy({ left: Math.max(180, track.clientWidth * .72), behavior: 'smooth' });
    });
});

const heroSlider = document.querySelector('[data-hero-slider]');
if (heroSlider) {
    const slides = [...heroSlider.querySelectorAll('.hero-slide')];
    const dots = [...heroSlider.querySelectorAll('[data-hero-dot]')];
    const prevButton = heroSlider.querySelector('[data-hero-prev]');
    const nextButton = heroSlider.querySelector('[data-hero-next]');
    let activeSlide = 0;
    let pointerStartX = null;
    let pointerStartY = null;
    let pointerId = null;
    const loadHeroImage = (slide) => {
        const image = slide?.querySelector('.hero-slide-image[data-src]');
        if (!image) return;
        image.src = image.dataset.src;
        image.removeAttribute('data-src');
    };
    const showHeroSlide = (index) => {
        if (slides.length === 0) return;
        activeSlide = (index + slides.length) % slides.length;
        loadHeroImage(slides[activeSlide]);
        slides.forEach((slide, slideIndex) => slide.classList.toggle('is-active', slideIndex === activeSlide));
        dots.forEach((dot, dotIndex) => dot.classList.toggle('is-active', dotIndex === activeSlide));
    };
    dots.forEach((dot) => dot.addEventListener('click', () => showHeroSlide(Number(dot.dataset.heroDot))));
    prevButton?.addEventListener('click', () => showHeroSlide(activeSlide - 1));
    nextButton?.addEventListener('click', () => showHeroSlide(activeSlide + 1));
    heroSlider.addEventListener('pointerdown', (event) => {
        if (slides.length < 2 || event.target.closest('a, button')) return;
        pointerStartX = event.clientX;
        pointerStartY = event.clientY;
        pointerId = event.pointerId;
        heroSlider.setPointerCapture?.(pointerId);
    });
    heroSlider.addEventListener('pointerup', (event) => {
        if (pointerStartX === null || pointerId !== event.pointerId) return;
        const deltaX = event.clientX - pointerStartX;
        const deltaY = event.clientY - pointerStartY;
        pointerStartX = null;
        pointerStartY = null;
        pointerId = null;
        if (Math.abs(deltaX) < 45 || Math.abs(deltaX) < Math.abs(deltaY) * 1.25) return;
        showHeroSlide(activeSlide + (deltaX < 0 ? 1 : -1));
    });
    heroSlider.addEventListener('pointercancel', () => {
        pointerStartX = null;
        pointerStartY = null;
        pointerId = null;
    });
    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        window.setInterval(() => showHeroSlide(activeSlide + 1), 15000);
    }
}

const productGallery = document.querySelector('[data-product-gallery]');
if (productGallery) {
    const mainImage = productGallery.querySelector('[data-gallery-main]');
    const thumbs = [...productGallery.querySelectorAll('[data-gallery-thumb]')];
    const current = productGallery.querySelector('[data-gallery-current]');
    const dots = [...productGallery.querySelectorAll('[data-gallery-dots] span')];
    const visibleThumbs = () => window.matchMedia('(max-width: 760px)').matches ? 5 : 8;
    let activeImage = 0;

    const lightbox = document.querySelector('[data-image-lightbox]');
    const lightboxImg = lightbox?.querySelector('[data-image-lightbox-img]');
    const lightboxCurrent = lightbox?.querySelector('[data-image-lightbox-current]');
    const imageCount = thumbs.length > 0 ? thumbs.length : (mainImage ? 1 : 0);

    const syncLightbox = () => {
        if (!lightbox?.classList.contains('is-open') || !lightboxImg || !mainImage) return;
        lightboxImg.src = mainImage.src;
        lightboxImg.alt = mainImage.alt;
        if (lightboxCurrent) lightboxCurrent.textContent = activeImage + 1;
    };

    const showGalleryImage = (index) => {
        if (!mainImage || thumbs.length === 0) return;

        activeImage = (index + thumbs.length) % thumbs.length;
        const activeThumb = thumbs[activeImage];
        const thumbWindowStart = Math.floor(activeImage / visibleThumbs()) * visibleThumbs();
        const thumbWindowEnd = thumbWindowStart + visibleThumbs();
        mainImage.src = activeThumb.dataset.galleryThumb;
        mainImage.alt = activeThumb.querySelector('img')?.alt ?? mainImage.alt;
        if (current) current.textContent = activeImage + 1;
        thumbs.forEach((thumb, thumbIndex) => {
            thumb.classList.toggle('is-active', thumbIndex === activeImage);
            thumb.hidden = thumbIndex < thumbWindowStart || thumbIndex >= thumbWindowEnd;
        });
        dots.forEach((dot, dotIndex) => dot.classList.toggle('is-active', dotIndex === activeImage));
        syncLightbox();
    };

    thumbs.forEach((thumb) => thumb.addEventListener('click', () => showGalleryImage(Number(thumb.dataset.galleryIndex))));
    productGallery.querySelector('[data-gallery-prev]')?.addEventListener('click', () => showGalleryImage(activeImage - 1));
    productGallery.querySelector('[data-gallery-next]')?.addEventListener('click', () => showGalleryImage(activeImage + 1));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft') showGalleryImage(activeImage - 1);
        if (event.key === 'ArrowRight') showGalleryImage(activeImage + 1);
    });
    window.addEventListener('resize', () => showGalleryImage(activeImage));
    if (thumbs.length > 0) showGalleryImage(0);

    let justSwiped = false;
    if (thumbs.length > 1) {
        let touchStartX = null;
        mainImage.addEventListener('touchstart', (event) => { touchStartX = event.touches[0].clientX; }, { passive: true });
        mainImage.addEventListener('touchend', (event) => {
            if (touchStartX === null) return;
            const deltaX = event.changedTouches[0].clientX - touchStartX;
            touchStartX = null;
            if (Math.abs(deltaX) < 40) return;
            justSwiped = true;
            showGalleryImage(activeImage + (deltaX < 0 ? 1 : -1));
        }, { passive: true });
    }

    if (lightbox && mainImage && imageCount > 0) {
        const lightboxTotal = lightbox.querySelector('[data-image-lightbox-total]');
        const lightboxPrev = lightbox.querySelector('[data-image-lightbox-prev]');
        const lightboxNext = lightbox.querySelector('[data-image-lightbox-next]');
        const lightboxCounter = lightbox.querySelector('[data-image-lightbox-counter]');
        if (lightboxTotal) lightboxTotal.textContent = imageCount;
        if (imageCount < 2) {
            lightboxPrev?.setAttribute('hidden', 'hidden');
            lightboxNext?.setAttribute('hidden', 'hidden');
            lightboxCounter?.setAttribute('hidden', 'hidden');
        }

        const openLightbox = () => {
            lightbox.classList.add('is-open');
            document.body.classList.add('panel-open');
            lightboxImg.src = mainImage.src;
            lightboxImg.alt = mainImage.alt;
            if (lightboxCurrent) lightboxCurrent.textContent = activeImage + 1;
        };
        const closeLightbox = () => {
            lightbox.classList.remove('is-open');
            document.body.classList.remove('panel-open');
        };

        mainImage.addEventListener('click', () => {
            if (justSwiped) {
                justSwiped = false;

                return;
            }
            openLightbox();
        });
        mainImage.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openLightbox();
            }
        });
        lightbox.querySelector('[data-close-image-lightbox]')?.addEventListener('click', closeLightbox);
        lightbox.addEventListener('click', (event) => {
            if (event.target === lightbox) closeLightbox();
        });
        lightboxPrev?.addEventListener('click', () => showGalleryImage(activeImage - 1));
        lightboxNext?.addEventListener('click', () => showGalleryImage(activeImage + 1));
        document.addEventListener('keydown', (event) => {
            if (!lightbox.classList.contains('is-open')) return;
            if (event.key === 'Escape') closeLightbox();
        });
    }
}

document.querySelectorAll('[data-copy-product-specs]').forEach((button) => {
    button.addEventListener('click', async () => {
        let text = '';

        try {
            text = JSON.parse(button.dataset.copyText ?? '""');
        } catch {
            text = button.dataset.copyText ?? '';
        }

        if (!text) {
            showToast(t('Немає характеристик для копіювання'), 'error');

            return;
        }

        try {
            await navigator.clipboard.writeText(text);
            showToast(t('Характеристики скопійовано'));
        } catch {
            showToast(t('Не вдалося скопіювати характеристики'), 'error');
        }
    });
});

const closePanels = () => {
    catalog?.classList.remove('is-open');
    catalog?.removeAttribute('data-mega-locked');
    drawer?.classList.remove('is-open');
    auth?.classList.remove('is-open');
    quickOrder?.classList.remove('is-open');
    overlay?.classList.remove('is-open', 'is-mega', 'is-panel');
    document.body.classList.remove('panel-open', 'drawer-open');
    catalogNav?.querySelectorAll('[data-mega-root]').forEach((link) => link.classList.remove('is-current'));
};

const catalogMenu = catalog?.querySelector('[data-catalog-menu]');
const catalogNav = document.querySelector('[data-catalog-nav]');
let catalogMenuRequest = null;
let megaCloseTimer = null;
const catalogMenuVersion = 'mega-v7';

const loadCatalogMenu = async () => {
    if (!catalogMenu || !catalogMenuUrl || (catalogMenu.dataset.loaded === 'true' && catalogMenu.dataset.version === catalogMenuVersion)) return;
    if (catalogMenuRequest) return catalogMenuRequest;

    if (catalogMenu.dataset.version !== catalogMenuVersion) {
        catalogMenu.dataset.loaded = 'false';
    }

    catalogMenuRequest = fetch(`${catalogMenuUrl}${catalogMenuUrl.includes('?') ? '&' : '?'}v=${catalogMenuVersion}`, {
        headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store',
    })
        .then((response) => {
            if (!response.ok) throw new Error();
            return response.text();
        })
        .then((html) => {
            catalogMenu.innerHTML = html;
            catalogMenu.dataset.loaded = 'true';
            catalogMenu.dataset.version = catalogMenuVersion;
        })
        .catch(() => {
            catalogMenu.innerHTML = `<div class="catalog-menu-state is-error">${escapeHtml(t('Не вдалося завантажити каталог.'))}</div>`;
            catalogMenuRequest = null;
        });

    return catalogMenuRequest;
};

const activateMegaPanel = (slug) => {
    if (!slug) {
        slug = catalog?.querySelector('[data-mega-panel]')?.dataset.megaPanel;
    }

    catalog?.querySelectorAll('[data-mega-panel]').forEach((panel) => {
        panel.classList.toggle('is-active', panel.dataset.megaPanel === String(slug));
    });
    catalog?.querySelectorAll('[data-mega-root-mobile]').forEach((button) => {
        button.classList.toggle('is-current', button.dataset.megaRootMobile === String(slug));
    });
    catalogNav?.querySelectorAll('[data-mega-root]').forEach((link) => {
        link.classList.toggle('is-current', link.dataset.megaRoot === String(slug));
    });
};

const openCatalog = async (slug, { lock = true } = {}) => {
    cancelMegaClose();
    drawer?.classList.remove('is-open');
    auth?.classList.remove('is-open');
    quickOrder?.classList.remove('is-open');
    catalog?.classList.add('is-open');
    overlay?.classList.remove('is-panel');
    overlay?.classList.add('is-open');
    overlay?.classList.toggle('is-mega', !lock);
    if (lock) {
        catalog?.setAttribute('data-mega-locked', 'true');
        document.body.classList.add('panel-open');
    } else {
        catalog?.removeAttribute('data-mega-locked');
        document.body.classList.remove('panel-open');
    }
    await loadCatalogMenu();
    activateMegaPanel(slug);
};

const cancelMegaClose = () => {
    clearTimeout(megaCloseTimer);
    megaCloseTimer = null;
};

const scheduleMegaClose = () => {
    if (catalog?.dataset.megaLocked === 'true') return;
    megaCloseTimer = setTimeout(() => closePanels(), 180);
};

catalogMenu?.addEventListener('click', (event) => {
    const moreButton = event.target.closest('[data-mega-more]');

    if (moreButton) {
        event.preventDefault();
        event.stopPropagation();
        const extra = moreButton.parentElement?.querySelector('.mega-extra');
        if (extra) extra.hidden = false;
        moreButton.remove();
        return;
    }

    const mobileRoot = event.target.closest('[data-mega-root-mobile]');
    if (mobileRoot) {
        event.preventDefault();
        event.stopPropagation();
        activateMegaPanel(mobileRoot.dataset.megaRootMobile);
        if (catalog) catalog.scrollTop = 0;
        return;
    }

    const drillButton = event.target.closest('[data-menu-drill], .menu-child');
    const backButton = event.target.closest('[data-menu-back]');

    if (!drillButton && !backButton) return;

    const template = drillButton
        ? drillButton.closest('.menu-branch, .menu-group')?.querySelector(':scope > [data-menu-template]')
        : null;

    if (drillButton && !template && !drillButton.matches('[data-menu-drill]')) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    const group = event.target.closest('.menu-group');
    const panel = group?.querySelector('.menu-children');

    if (!group || !panel) return;

    group._menuStack ??= [];

    if (backButton) {
        const previousHtml = group._menuStack.pop();

        if (previousHtml !== undefined) {
            panel.innerHTML = previousHtml;
            group.classList.toggle('is-drilled', group._menuStack.length > 0);
        }

        return;
    }

    if (!template) return;

    group._menuStack.push(panel.innerHTML);
    panel.innerHTML = template.innerHTML;
    group.classList.add('is-drilled');
});

const openPanel = (panel) => {
    closePanels();
    panel?.classList.add('is-open');
    overlay?.classList.add('is-open', 'is-panel');
    document.body.classList.add('panel-open', 'drawer-open');
};

document.addEventListener('click', (event) => {
    if (event.target.closest('[data-open-catalog]')) {
        const current = catalogNav?.querySelector('[data-mega-root]')?.dataset.megaRoot;
        openCatalog(current, { lock: true });
    }
    if (event.target.closest('[data-open-cart]')) openPanel(drawer);
    if (event.target.closest('[data-open-auth]')) openPanel(auth);
    if (event.target.closest('[data-close-catalog], [data-close-cart], [data-close-auth], [data-close-quick-order], [data-overlay]')) closePanels();
});

catalogNav?.addEventListener('mouseenter', () => {
    loadCatalogMenu();
}, { once: true });

catalogNav?.addEventListener('mouseover', (event) => {
    const root = event.target.closest('[data-mega-root]');
    if (!root) return;
    cancelMegaClose();
    openCatalog(root.dataset.megaRoot, { lock: false });
});

catalogNav?.addEventListener('mouseleave', scheduleMegaClose);
catalog?.addEventListener('mouseenter', cancelMegaClose);
catalog?.addEventListener('mouseleave', scheduleMegaClose);

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-open-quick-order]');
    if (!button || !quickOrder) return;
    quickOrder.querySelector('input[name="product_id"]').value = button.dataset.productId;
    quickOrder.querySelector('[data-quick-order-product]').textContent = button.dataset.productName;
    openPanel(quickOrder);
    quickOrder.querySelector('input[name="phone"]').focus();
});

const catalogResults = document.querySelector('[data-catalog-results]');
const filterForm = document.querySelector('[data-filter-form]');
const toolbarForm = document.querySelector('[data-toolbar-form]');
let catalogFilterRefreshId = 0;

const relativeSiteUrl = (href) => {
    try {
        const url = new URL(href, window.location.href);

        return `${url.pathname}${url.search}${url.hash}`;
    } catch {
        return href;
    }
};

const recordFilterClick = (input) => {
    if (!filterClickUrl || !input?.dataset.filterKey) return;
    if (input.type === 'checkbox' && !input.checked) return;
    if (input.type !== 'checkbox' && !input.value) return;

    const payload = new FormData();
    payload.append('_token', csrfToken ?? '');
    payload.append('filter_key', input.dataset.filterKey);
    payload.append('filter_value', input.dataset.filterValue || input.value);

    const categoryId = filterForm?.dataset.filterCategory;
    if (categoryId) payload.append('category_id', categoryId);

    if (navigator.sendBeacon) {
        navigator.sendBeacon(filterClickUrl, payload);

        return;
    }

    fetch(filterClickUrl, {
        method: 'POST',
        body: payload,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        keepalive: true,
    }).catch(() => {});
};

const replaceCatalog = async (url, pushState = true) => {
    const cleanUrl = relativeSiteUrl(url instanceof URL ? url.toString() : String(url)).replace(/\?$/, '');
    catalogResults?.classList.add('is-loading');
    setGlobalLoading(true);
    try {
        const response = await fetch(cleanUrl, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) throw new Error(t('Не вдалося завантажити товари.'));
        const data = await response.json();
        const responseUrl = relativeSiteUrl(String(data.url ?? cleanUrl)).replace(/\?$/, '');
        catalogResults.innerHTML = data.html;
        const filterFields = document.querySelector('[data-filter-fields]');
        if (filterFields && data.filters) {
            filterFields.innerHTML = data.filters;
            initFilterGroups(filterFields);
        }
        updateProductButtons(window.storeState?.cartProductIds ?? []);
        updateListButtons(window.storeState?.favoriteIds ?? [], window.storeState?.comparisonIds ?? []);
        const catalogHeading = document.querySelector('[data-catalog-heading]');
        if (catalogHeading && data.heading) catalogHeading.textContent = data.heading;
        if (data.title) document.title = data.title;
        if (pushState) window.history.pushState({}, '', responseUrl);
    } finally {
        catalogResults?.classList.remove('is-loading');
        setGlobalLoading(false);
    }
};

const refreshCatalogFilters = async (url) => {
    const refreshId = ++catalogFilterRefreshId;
    const refreshUrl = new URL(url instanceof URL ? url.toString() : String(url), window.location.href);
    refreshUrl.searchParams.delete('fast_filters');
    refreshUrl.searchParams.set('filters_only', '1');

    try {
        const response = await fetch(relativeSiteUrl(refreshUrl.toString()), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) return;

        const data = await response.json();
        if (refreshId !== catalogFilterRefreshId || !data.filters) return;

        const expectedUrl = relativeSiteUrl(String(data.url ?? refreshUrl.toString())).replace(/\?$/, '');
        const currentUrl = relativeSiteUrl(window.location.href).replace(/\?$/, '');
        if (expectedUrl !== currentUrl) return;

        const filterFields = document.querySelector('[data-filter-fields]');
        const mobileFilters = document.querySelector('[data-mobile-filters]');
        const scrollTop = mobileFilters?.scrollTop ?? 0;
        if (filterFields) {
            filterFields.innerHTML = data.filters;
            initFilterGroups(filterFields);
            if (mobileFilters) requestAnimationFrame(() => { mobileFilters.scrollTop = scrollTop; });
        }
    } catch {
        // The products are already current. Existing facet counts can remain
        // visible until the next interaction if the background refresh fails.
    }
};

if (document.querySelector('[data-filter-fields][data-deferred-filters]')) {
    void refreshCatalogFilters(window.location.href);
}

filterForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const url = new URL(filterForm.action);
    const currentUrl = new URL(window.location.href);

    ['sort', 'per_page'].forEach((key) => {
        if (currentUrl.searchParams.has(key)) {
            url.searchParams.set(key, currentUrl.searchParams.get(key));
        }
    });

    new FormData(filterForm).forEach((value, key) => url.searchParams.append(key, value));
    url.searchParams.delete('page');
    const mobileFilters = document.querySelector('[data-mobile-filters]');
    const keepMobileFiltersOpen = window.matchMedia('(max-width: 760px)').matches && mobileFilters?.classList.contains('is-open');
    const closeMobileFiltersAfterSubmit = keepMobileFiltersOpen && event.submitter?.matches('[data-apply-mobile-filters]');
    const mobileFiltersScrollTop = mobileFilters?.scrollTop ?? 0;
    try {
        const fastUrl = new URL(url);
        fastUrl.searchParams.set('fast_filters', '1');
        await replaceCatalog(fastUrl);
        void refreshCatalogFilters(url);
        if (closeMobileFiltersAfterSubmit) {
            closeMobileSheets();
        } else if (keepMobileFiltersOpen && mobileFilters) {
            requestAnimationFrame(() => {
                mobileFilters.scrollTop = mobileFiltersScrollTop;
            });
        }
    } catch (error) {
        showToast(error.message, 'error');
    }
});

filterForm?.addEventListener('change', (event) => {
    recordFilterClick(event.target.closest('[data-filter-key]'));
    filterForm.requestSubmit();
});
filterForm?.addEventListener('click', (event) => {
    const adminDisableButton = event.target.closest('[data-admin-disable-filter]');
    if (adminDisableButton) {
        event.preventDefault();
        event.stopPropagation();

        const message = adminDisableButton.dataset.includeChildren
            ? t('Вимкнути цей фільтр у категорії та дочірніх?')
            : t('Вимкнути цей фільтр у цій категорії?');

        if (!window.confirm(message)) return;

        const payload = new FormData();
        payload.append('_token', csrfToken ?? '');
        payload.append('category_id', adminDisableButton.dataset.categoryId ?? '');
        payload.append('type', adminDisableButton.dataset.filterType ?? '');
        payload.append('key', adminDisableButton.dataset.filterKey ?? '');
        if (adminDisableButton.dataset.includeChildren) payload.append('include_children', '1');

        setGlobalLoading(true);
        fetch(adminDisableButton.dataset.url, {
            method: 'POST',
            body: payload,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((response) => {
                if (!response.ok) throw new Error(t('Не вдалося виконати дію.'));

                return response.json();
            })
            .then((data) => {
                showToast(data.message || t('Фільтр вимкнено.'), 'success');
                return replaceCatalog(data.url || window.location.href);
            })
            .catch((error) => showToast(error.message, 'error'))
            .finally(() => setGlobalLoading(false));

        return;
    }

    const seoLink = event.target.closest('[data-filter-option-link]');
    if (!seoLink) return;

    event.preventDefault();
    const checkbox = seoLink.closest('[data-filter-option]')?.querySelector('input[type="checkbox"]');
    if (!checkbox || checkbox.checked) return;

    checkbox.checked = true;
    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
});
toolbarForm?.addEventListener('change', async () => {
    const url = new URL(window.location.href);
    const formData = new FormData(toolbarForm);

    if (formData.has('sort')) {
        url.searchParams.set('sort', formData.get('sort'));
    }

    if (formData.has('per_page')) {
        url.searchParams.set('per_page', formData.get('per_page'));
    }

    url.searchParams.delete('page');
    url.searchParams.set('fast_filters', '1');
    try {
        await replaceCatalog(url);
        closeMobileSheets();
    } catch (error) {
        showToast(error.message, 'error');
    }
});

const materializeFilterOptions = (group) => {
    const payloadElement = group.querySelector('[data-filter-options-payload]');
    if (!payloadElement) return;

    let payload;
    try {
        payload = JSON.parse(payloadElement.textContent);
    } catch {
        payloadElement.remove();
        return;
    }

    const list = group.querySelector('[data-filter-options-list]');
    const selected = new Set((payload.selected ?? []).map(String));

    (payload.options ?? []).forEach(({ value, count }) => {
        const label = document.createElement('label');
        const text = document.createElement('span');
        const input = document.createElement('input');
        const optionCount = document.createElement('small');

        label.dataset.filterOption = payload.name;
        label.dataset.filterExtra = '';
        input.type = 'checkbox';
        input.name = `${payload.name}[]`;
        input.value = value;
        input.dataset.filterKey = payload.trackingKey;
        input.checked = selected.has(String(value));
        text.append(input, document.createTextNode(` ${value}`));
        optionCount.className = 'filter-option-count';
        optionCount.textContent = `(${count})`;
        label.append(text, optionCount);
        list.append(label);
    });

    payloadElement.remove();
};

const refreshFilterGroup = (group) => {
    const search = group.querySelector('[data-filter-search]');
    const term = search?.value.toLowerCase().trim() ?? '';
    const expanded = group.classList.contains('is-expanded');

    if (term || expanded) materializeFilterOptions(group);

    group.querySelectorAll('[data-filter-option]').forEach((option) => {
        const matches = option.textContent.toLowerCase().includes(term);
        option.hidden = !matches || (option.hasAttribute('data-filter-extra') && !expanded && !term);
    });

    const toggle = group.querySelector('[data-toggle-filter-options]');
    if (!toggle) return;
    toggle.hidden = Boolean(term) || expanded;
};

const initFilterGroups = (root = document) => {
    root.querySelectorAll('[data-filter-group]').forEach((group) => {
        refreshFilterGroup(group);
        group.querySelector('[data-filter-search]')?.addEventListener('input', () => refreshFilterGroup(group));
    });
};

initFilterGroups();

const checkoutProgress = document.querySelector('.checkout-progress');
const checkoutSections = [...document.querySelectorAll('[data-checkout-section]')];
const checkoutButtons = [...document.querySelectorAll('[data-checkout-step-target]')];
const checkoutWizard = document.querySelector('[data-checkout-wizard]');
const checkoutAccountPrompt = document.querySelector('[data-checkout-account-prompt]');
const checkoutDraftKey = 'kubii.checkout-draft';
let checkoutAccountTimer = null;
let checkoutAccountRequest = null;

const checkoutDraft = () => {
    if (!checkoutWizard) return {};

    return [...new FormData(checkoutWizard).entries()].reduce((draft, [name, value]) => {
        if (name !== '_token') draft[name] = value;
        return draft;
    }, {});
};

const saveCheckoutDraft = () => {
    try {
        sessionStorage.setItem(checkoutDraftKey, JSON.stringify(checkoutDraft()));
    } catch {
        // Checkout still works if browser storage is unavailable.
    }
};

const restoreCheckoutDraft = () => {
    if (!checkoutWizard) return;

    try {
        const draft = JSON.parse(sessionStorage.getItem(checkoutDraftKey) ?? 'null');
        if (!draft) return;

        Object.entries(draft).forEach(([name, value]) => {
            if (['email', 'phone'].includes(name)) return;
            const field = checkoutWizard.elements.namedItem(name);
            if (!field || field.readOnly || field.disabled) return;
            field.value = value;
        });
        sessionStorage.removeItem(checkoutDraftKey);
    } catch {
        sessionStorage.removeItem(checkoutDraftKey);
    }
};

const showCheckoutAccountPrompt = ({ email_exists: emailExists, phone_exists: phoneExists, login_email: loginEmail } = {}) => {
    if (!checkoutAccountPrompt) return;
    const message = checkoutAccountPrompt.querySelector('[data-checkout-account-message]');
    checkoutAccountPrompt.dataset.loginEmail = loginEmail ?? '';
    checkoutAccountPrompt.hidden = false;

    if (message) {
        message.textContent = emailExists && phoneExists
            ? t('Ці email і телефон уже прив’язані до профілю. Увійдіть, щоб продовжити.')
            : t(emailExists
                ? 'Цей email уже прив’язаний до профілю. Увійдіть, щоб продовжити.'
                : 'Цей номер телефону уже прив’язаний до профілю. Увійдіть, щоб продовжити.');
    }

    updateCheckoutStatuses();
};

const checkCheckoutAccount = async () => {
    if (!checkoutWizard?.dataset.accountCheckUrl || !checkoutAccountPrompt) return;
    const email = checkoutWizard.elements.namedItem('email');
    const phone = checkoutWizard.elements.namedItem('phone');
    const emailValue = email?.checkValidity() ? email.value.trim() : '';
    const phoneValue = (phone?.value.match(/\d/g) ?? []).length >= 10 ? phone.value.trim() : '';

    if (!emailValue && !phoneValue) {
        checkoutAccountPrompt.hidden = true;
        updateCheckoutStatuses();
        return;
    }

    checkoutAccountRequest?.abort();
    checkoutAccountRequest = new AbortController();

    try {
        const response = await fetch(checkoutWizard.dataset.accountCheckUrl, {
            method: 'POST',
            body: new URLSearchParams({ email: emailValue, phone: phoneValue }),
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: checkoutAccountRequest.signal,
        });
        if (!response.ok) return;
        const data = await response.json();
        if (data.exists) showCheckoutAccountPrompt(data);
        else {
            checkoutAccountPrompt.hidden = true;
            updateCheckoutStatuses();
        }
    } catch (error) {
        if (error.name !== 'AbortError') checkoutAccountPrompt.hidden = true;
    }
};

restoreCheckoutDraft();

let novaPoshtaPointsLoading = false;
let deliveryPriceRequest = null;
let deliveryPriceTimer = null;
let deliveryPriceRequestSequence = 0;

const scheduleDeliveryPriceUpdate = () => {
    window.clearTimeout(deliveryPriceTimer);
    deliveryPriceTimer = window.setTimeout(updateDeliveryPriceEstimate, 300);
};

const updateDeliveryPriceEstimate = async () => {
    const root = document.querySelector('[data-nova-poshta]');
    const priceEstimate = document.querySelector('[data-checkout-delivery-estimate]');
    const deliveryPriceRoot = document.querySelector('[data-checkout-delivery-price]');
    const deliveryLabel = document.querySelector('[data-checkout-delivery-label]');
    if (!root || !priceEstimate) return;

    const url = root.dataset.deliveryPriceUrl;
    const cityRef = root.querySelector('[data-nova-poshta-city-ref]')?.value.trim();
    const deliveryType = root.querySelector('input[name="delivery_type"]:checked')?.value ?? 'nova_poshta_warehouse';
    const warehouseRef = deliveryType === 'nova_poshta_courier'
        ? ''
        : (root.querySelector('[data-nova-poshta-point-ref]')?.value.trim() ?? '');

    if (!url || !cityRef) {
        priceEstimate.textContent = deliveryPriceRoot?.dataset.freeDeliveryQualified === '1'
            ? ''
            : (priceEstimate.dataset.promoLabel ?? priceEstimate.textContent);
        if (deliveryPriceRoot?.dataset.freeDeliveryQualified === '1') {
            priceEstimate.setAttribute('hidden', '');
        } else if (priceEstimate.textContent.trim() !== '') {
            priceEstimate.removeAttribute('hidden');
        } else {
            priceEstimate.setAttribute('hidden', '');
        }
        return;
    }

    if (deliveryPriceRoot?.dataset.freeDeliveryQualified === '1') {
        if (deliveryLabel) {
            deliveryLabel.textContent = t('Безкоштовно');
        }
        deliveryPriceRoot.classList.add('is-free-delivery');
        priceEstimate.textContent = '';
        priceEstimate.setAttribute('hidden', '');
        return;
    }

    deliveryPriceRequest?.abort();
    deliveryPriceRequest = new AbortController();
    const requestSequence = ++deliveryPriceRequestSequence;
    const previousEstimate = priceEstimate.textContent.trim();
    const calculatingLabel = t('Розраховуємо...');
    priceEstimate.textContent = calculatingLabel;
    priceEstimate.removeAttribute('hidden');

    try {
        const endpoint = new URL(url, window.location.origin);
        endpoint.searchParams.set('city_ref', cityRef);
        endpoint.searchParams.set('delivery_type', deliveryType);
        if (warehouseRef) {
            endpoint.searchParams.set('warehouse_ref', warehouseRef);
        }

        const response = await fetch(endpoint, {
            signal: deliveryPriceRequest.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store',
        });
        const data = await response.json();

        if (requestSequence !== deliveryPriceRequestSequence) {
            return;
        }

        if (!response.ok) {
            throw new Error(data.message);
        }

        const estimate = data.data;

        if (estimate?.free || estimate?.cost === 0) {
            if (deliveryLabel) {
                deliveryLabel.textContent = t('Безкоштовно');
            }
            deliveryPriceRoot?.classList.add('is-free-delivery');
            priceEstimate.textContent = '';
            priceEstimate.setAttribute('hidden', '');
            return;
        }

        deliveryPriceRoot?.classList.remove('is-free-delivery');
        if (deliveryLabel && deliveryPriceRoot?.dataset.freeDeliveryQualified !== '1') {
            deliveryLabel.textContent = t('За тарифом перевізника');
        }

        if (estimate?.available && estimate.cost != null) {
            const price = estimate.formatted ?? `~${estimate.cost} ₴`;
            priceEstimate.textContent = t('Орієнтовна вартість: :price').replace(':price', price);
            priceEstimate.removeAttribute('hidden');
            return;
        }

        if (previousEstimate && previousEstimate !== calculatingLabel) {
            priceEstimate.textContent = previousEstimate;
            priceEstimate.removeAttribute('hidden');
            return;
        }

        priceEstimate.textContent = '';
        priceEstimate.setAttribute('hidden', '');
    } catch (error) {
        if (error.name === 'AbortError' || requestSequence !== deliveryPriceRequestSequence) {
            return;
        }

        if (previousEstimate && previousEstimate !== calculatingLabel) {
            priceEstimate.textContent = previousEstimate;
            priceEstimate.removeAttribute('hidden');
            return;
        }

        priceEstimate.textContent = '';
        priceEstimate.setAttribute('hidden', '');
    }
};

const novaPoshta = document.querySelector('[data-nova-poshta]');

if (novaPoshta) {
    const cityInput = novaPoshta.querySelector('[data-nova-poshta-city-input]');
    const cityRefInput = novaPoshta.querySelector('[data-nova-poshta-city-ref]');
    const cityResults = novaPoshta.querySelector('[data-nova-poshta-cities]');
    const pointInput = novaPoshta.querySelector('[data-nova-poshta-point-input]');
    const pointRefInput = novaPoshta.querySelector('[data-nova-poshta-point-ref]');
    const pointResults = novaPoshta.querySelector('[data-nova-poshta-points]');
    const pointLabel = novaPoshta.querySelector('[data-nova-poshta-point-label]');
    const pointTitle = novaPoshta.querySelector('[data-nova-poshta-point-title]');
    const pointHelp = novaPoshta.querySelector('[data-nova-poshta-point-help]');
    const pointStage = novaPoshta.querySelector('[data-nova-poshta-point-stage]');
    const courierStage = novaPoshta.querySelector('[data-nova-poshta-courier-stage]');
    const streetInput = novaPoshta.querySelector('[data-nova-poshta-street-input]');
    const streetRefInput = novaPoshta.querySelector('[data-nova-poshta-street-ref]');
    const streetDescriptionInput = novaPoshta.querySelector('[data-nova-poshta-street-description-input]');
    const streetResults = novaPoshta.querySelector('[data-nova-poshta-streets]');
    const buildingInput = novaPoshta.querySelector('[data-nova-poshta-building-input]');
    const flatInput = novaPoshta.querySelector('[data-nova-poshta-flat-input]');
    const selectedPoint = novaPoshta.querySelector('[data-nova-poshta-selected]');
    const state = novaPoshta.querySelector('[data-nova-poshta-state]');
    let cityTimer = null;
    let cityRequest = null;
    let pointRequest = null;
    let streetRequest = null;
    let streetTimer = null;
    let selectedWarehouse = novaPoshta.dataset.selectedWarehouse || pointRefInput.value;
    let selectedStreet = novaPoshta.dataset.selectedStreet || streetRefInput.value;
    let points = [];

    const deliveryType = () => novaPoshta.querySelector('input[name="delivery_type"]:checked')?.value ?? 'nova_poshta_warehouse';
    const isPostomat = () => deliveryType() === 'nova_poshta_postomat';
    const isCourier = () => deliveryType() === 'nova_poshta_courier';
    const toggleDeliveryStage = () => {
        const courier = isCourier();
        pointStage.hidden = courier;
        courierStage.hidden = !courier;
        pointInput.required = !courier;
        pointInput.disabled = courier || !cityRefInput.value;
        streetInput.required = courier;
        streetInput.disabled = !courier || !cityRefInput.value;
        buildingInput.required = courier;
        buildingInput.disabled = !courier;
        flatInput.disabled = !courier;
    };
    const setNovaPoshtaState = (message = '', error = false) => {
        state.textContent = message;
        state.hidden = !message;
        state.classList.toggle('is-error', error);
    };
    const resetCourierFields = () => {
        streetRequest?.abort();
        streetInput.value = '';
        streetInput.placeholder = t('Спочатку оберіть місто');
        streetInput.disabled = true;
        streetInput.setCustomValidity('');
        streetRefInput.value = '';
        if (streetDescriptionInput) {
            streetDescriptionInput.value = '';
        }
        buildingInput.value = '';
        flatInput.value = '';
        streetResults.hidden = true;
        streetResults.innerHTML = '';
        selectedStreet = '';
        updateCheckoutStatuses();
    };
    const resetPoints = () => {
        pointRequest?.abort();
        points = [];
        pointInput.value = '';
        pointInput.placeholder = t('Спочатку оберіть місто');
        pointInput.disabled = true;
        pointInput.setCustomValidity('');
        pointRefInput.value = '';
        pointResults.hidden = true;
        pointResults.innerHTML = '';
        selectedPoint.hidden = true;
        selectedPoint.innerHTML = '';
        selectedWarehouse = '';
        updateCheckoutStatuses();
    };
    const renderCities = (cities) => {
        cityResults.innerHTML = cities.map((city) => `
            <button type="button" data-nova-poshta-city-ref="${escapeHtml(city.Ref)}" data-nova-poshta-city-name="${escapeHtml(city.Description)}">
                <strong>${escapeHtml(city.Description)}</strong>
                <small>${escapeHtml([city.AreaDescription, city.RegionsDescription].filter(Boolean).join(', '))}</small>
            </button>
        `).join('');
        cityResults.hidden = cities.length === 0;
        setNovaPoshtaState(cities.length ? '' : t('Міст не знайдено.'));
    };
    const searchCities = async () => {
        const search = cityInput.value.trim();
        cityRequest?.abort();

        if (search.length < 2) {
            cityResults.hidden = true;
            setNovaPoshtaState(search ? t('Введіть щонайменше 2 символи назви міста.') : '');
            return;
        }

        cityRequest = new AbortController();
        setNovaPoshtaState(t('Шукаємо міста...'));

        try {
            const url = new URL(novaPoshta.dataset.citiesUrl, window.location.origin);
            url.searchParams.set('search', search);
            const response = await fetch(url, {
                signal: cityRequest.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message);
            renderCities(data.data ?? []);
        } catch (error) {
            if (error.name === 'AbortError') return;
            cityResults.hidden = true;
            setNovaPoshtaState(error.message || t('Не вдалося завантажити міста Нової Пошти.'), true);
        }
    };
    const pointText = (point) => point.Description || [point.Number, point.ShortAddress].filter(Boolean).join(' — ');
    const renderSelectedPoint = (point) => {
        selectedPoint.innerHTML = `
            <span>${escapeHtml(t('Обрано'))}</span>
            <div><strong>${escapeHtml(pointText(point))}</strong><small>${escapeHtml(point.ShortAddress || '')}</small></div>
            <button type="button" data-nova-poshta-change-point>${escapeHtml(t('Змінити'))}</button>
        `;
        selectedPoint.hidden = false;
    };
    const selectPoint = (point) => {
        pointInput.value = pointText(point);
        pointInput.setCustomValidity('');
        pointRefInput.value = point.Ref;
        pointResults.hidden = true;
        renderSelectedPoint(point);
        setNovaPoshtaState();
        updateCheckoutStatuses();
        updateDeliveryPriceEstimate();
    };
    const renderPoints = (search = '') => {
        const query = search.trim().toLocaleLowerCase();
        const filtered = points.filter((point) => [
            point.Number,
            point.Description,
            point.ShortAddress,
        ].some((value) => String(value ?? '').toLocaleLowerCase().includes(query)));

        pointResults.innerHTML = filtered.slice(0, 80).map((point) => `
            <button type="button" data-nova-poshta-point-ref="${escapeHtml(point.Ref)}">
                <strong>${escapeHtml(point.Description || `${t(isPostomat() ? 'Поштомат' : 'Відділення')} №${point.Number}`)}</strong>
                <small>${escapeHtml(point.ShortAddress || '')}</small>
            </button>
        `).join('');
        pointResults.hidden = false;
        setNovaPoshtaState(
            filtered.length
                ? t('Знайдено точок: :count').replace(':count', filtered.length)
                : t('Нічого не знайдено. Спробуйте номер або частину адреси.'),
            filtered.length === 0,
        );
    };
    const loadPoints = async () => {
        if (isCourier()) {
            resetPoints();
            return;
        }

        if (!cityRefInput.value) {
            resetPoints();
            return;
        }

        pointRequest?.abort();
        pointRequest = new AbortController();
        novaPoshtaPointsLoading = true;
        updateCheckoutStatuses();
        const postomat = isPostomat();
        const cargoOnly = novaPoshta.dataset.cargoOnly === '1';
        pointLabel.textContent = t(postomat ? 'Поштомат' : 'Відділення');
        pointTitle.textContent = t(postomat
            ? 'Оберіть поштомат'
            : (cargoOnly ? 'Оберіть вантажне відділення' : 'Оберіть відділення'));
        pointHelp.textContent = t(postomat
            ? 'Шукайте за номером поштомату, вулицею або адресою'
            : (cargoOnly
                ? 'Показані лише відділення, що приймають габарити вашого замовлення'
                : 'Шукайте за номером відділення, вулицею або адресою'));
        pointInput.value = '';
        pointInput.disabled = true;
        pointInput.placeholder = t(postomat ? 'Завантажуємо поштомати...' : 'Завантажуємо відділення...');
        pointInput.setCustomValidity('');
        pointRefInput.value = '';
        pointResults.hidden = true;
        selectedPoint.hidden = true;
        setNovaPoshtaState(t(postomat ? 'Завантажуємо поштомати...' : 'Завантажуємо відділення...'));
        updateCheckoutStatuses();

        try {
            const url = new URL(postomat ? novaPoshta.dataset.postomatsUrl : novaPoshta.dataset.warehousesUrl, window.location.origin);
            url.searchParams.set('city_ref', cityRefInput.value);
            const response = await fetch(url, {
                signal: pointRequest.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message);
            points = data.data ?? [];
            pointInput.disabled = points.length === 0;
            pointInput.placeholder = t(postomat ? 'Введіть номер або адресу поштомату' : 'Введіть номер або адресу відділення');
            const restoredPoint = points.find((point) => point.Ref === selectedWarehouse);
            if (restoredPoint) selectPoint(restoredPoint);
            selectedWarehouse = '';
            setNovaPoshtaState(points.length ? '' : t('У вибраному місті точок цього типу не знайдено.'), points.length === 0);
        } catch (error) {
            if (error.name === 'AbortError') return;
            points = [];
            pointInput.value = '';
            pointInput.disabled = true;
            pointInput.placeholder = t(postomat ? 'Оберіть поштомат' : 'Оберіть відділення');
            pointResults.hidden = true;
            setNovaPoshtaState(error.message || t('Не вдалося завантажити точки Нової Пошти.'), true);
        } finally {
            novaPoshtaPointsLoading = false;
            updateCheckoutStatuses();
            if (!isCourier()) {
                scheduleDeliveryPriceUpdate();
            }
        }
    };

    const renderStreets = (streets) => {
        streetResults.innerHTML = streets.map((street) => `
            <button type="button" data-nova-poshta-street-ref="${escapeHtml(street.Ref)}" data-nova-poshta-street-name="${escapeHtml(street.Label)}" data-nova-poshta-street-description="${escapeHtml(street.Description)}">
                <strong>${escapeHtml(street.Label)}</strong>
            </button>
        `).join('');
        streetResults.hidden = streets.length === 0;
        setNovaPoshtaState(streets.length ? '' : t('Вулиць не знайдено.'));
    };
    const searchStreets = async () => {
        const search = streetInput.value.trim();
        streetRequest?.abort();

        if (!cityRefInput.value) {
            streetResults.hidden = true;
            setNovaPoshtaState(t('Спочатку оберіть місто.'));
            return;
        }

        if (search.length < 2) {
            streetResults.hidden = true;
            setNovaPoshtaState(search ? t('Введіть щонайменше 2 символи назви вулиці.') : '');
            return;
        }

        streetRequest = new AbortController();
        setNovaPoshtaState(t('Шукаємо вулиці...'));

        try {
            const url = new URL(novaPoshta.dataset.streetsUrl, window.location.origin);
            url.searchParams.set('city_ref', cityRefInput.value);
            url.searchParams.set('search', search);
            const response = await fetch(url, {
                signal: streetRequest.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message);
            renderStreets(data.data ?? []);
        } catch (error) {
            if (error.name === 'AbortError') return;
            streetResults.hidden = true;
            setNovaPoshtaState(error.message || t('Не вдалося завантажити вулиці Нової Пошти.'), true);
        }
    };
    const selectStreet = (street) => {
        streetInput.value = street.Label;
        streetInput.setCustomValidity('');
        streetRefInput.value = street.Ref;
        if (streetDescriptionInput) {
            streetDescriptionInput.value = street.Description || '';
        }
        streetResults.hidden = true;
        setNovaPoshtaState();
        updateCheckoutStatuses();
    };
    const prepareCourierStage = (reset = true) => {
        if (!isCourier()) return;

        if (reset) {
            resetCourierFields();
        }

        if (!cityRefInput.value) return;

        streetInput.disabled = false;
        streetInput.placeholder = t('Наприклад, Хрещатик');
        buildingInput.disabled = false;
        flatInput.disabled = false;

        if (streetRefInput.value && streetInput.value) {
            streetInput.setCustomValidity('');
            selectedStreet = '';
        }
    };

    cityInput.addEventListener('input', () => {
        cityRefInput.value = '';
        cityInput.setCustomValidity(t('Оберіть місто зі списку Нової Пошти.'));
        resetPoints();
        resetCourierFields();
        toggleDeliveryStage();
        scheduleDeliveryPriceUpdate();
        window.clearTimeout(cityTimer);
        cityTimer = window.setTimeout(searchCities, 250);
    });
    cityResults.addEventListener('click', (event) => {
        const city = event.target.closest('[data-nova-poshta-city-ref]');
        if (!city) return;
        cityInput.value = city.dataset.novaPoshtaCityName;
        cityRefInput.value = city.dataset.novaPoshtaCityRef;
        cityInput.setCustomValidity('');
        cityResults.hidden = true;
        setNovaPoshtaState();
        if (isCourier()) {
            prepareCourierStage();
        } else {
            loadPoints();
        }
        scheduleDeliveryPriceUpdate();
    });
    streetInput.addEventListener('input', () => {
        streetRefInput.value = '';
        if (streetDescriptionInput) {
            streetDescriptionInput.value = '';
        }
        streetInput.setCustomValidity(t('Оберіть вулицю зі списку Нової Пошти.'));
        window.clearTimeout(streetTimer);
        streetTimer = window.setTimeout(searchStreets, 250);
        updateCheckoutStatuses();
    });
    streetResults.addEventListener('click', (event) => {
        const street = event.target.closest('[data-nova-poshta-street-ref]');
        if (!street) return;
        selectStreet({
            Ref: street.dataset.novaPoshtaStreetRef,
            Label: street.dataset.novaPoshtaStreetName,
            Description: street.dataset.novaPoshtaStreetDescription,
        });
    });
    buildingInput.addEventListener('input', () => updateCheckoutStatuses());
    pointInput.addEventListener('focus', () => {
        if (!pointInput.disabled && points.length) renderPoints(pointInput.value);
    });
    pointInput.addEventListener('input', () => {
        pointRefInput.value = '';
        selectedPoint.hidden = true;
        pointInput.setCustomValidity(t(isPostomat() ? 'Оберіть поштомат зі списку.' : 'Оберіть відділення зі списку.'));
        renderPoints(pointInput.value);
        updateCheckoutStatuses();
    });
    pointResults.addEventListener('click', (event) => {
        const result = event.target.closest('[data-nova-poshta-point-ref]');
        if (!result) return;
        const point = points.find((candidate) => candidate.Ref === result.dataset.novaPoshtaPointRef);
        if (point) selectPoint(point);
    });
    selectedPoint.addEventListener('click', (event) => {
        if (!event.target.closest('[data-nova-poshta-change-point]')) return;
        pointInput.focus();
        pointInput.select();
        renderPoints('');
    });
    novaPoshta.querySelectorAll('input[name="delivery_type"]').forEach((radio) => radio.addEventListener('change', () => {
        selectedWarehouse = '';
        selectedStreet = '';
        toggleDeliveryStage();
        if (isCourier()) {
            resetPoints();
            prepareCourierStage();
            updateDeliveryPriceEstimate();
        } else {
            resetCourierFields();
            loadPoints();
        }
    }));
    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-nova-poshta-city-input], [data-nova-poshta-cities]')) cityResults.hidden = true;
        if (!event.target.closest('[data-nova-poshta-point-input], [data-nova-poshta-points], [data-nova-poshta-selected]')) pointResults.hidden = true;
        if (!event.target.closest('[data-nova-poshta-street-input], [data-nova-poshta-streets]')) streetResults.hidden = true;
    });

    toggleDeliveryStage();
    if (cityRefInput.value && cityInput.value) {
        cityInput.setCustomValidity('');
        queueMicrotask(() => {
            if (isCourier()) {
                prepareCourierStage(false);
            } else {
                loadPoints();
            }
        });
        scheduleDeliveryPriceUpdate();
    }
}

const checkoutNovaPoshtaReady = () => {
    const root = document.querySelector('[data-nova-poshta]');
    if (!root || novaPoshtaPointsLoading) return false;

    const cityRef = root.querySelector('[data-nova-poshta-city-ref]')?.value.trim();
    if (!cityRef) return false;

    const courierSelected = root.querySelector('input[name="delivery_type"][value="nova_poshta_courier"]')?.checked;
    if (courierSelected) {
        const streetRef = root.querySelector('[data-nova-poshta-street-ref]')?.value.trim();
        const building = root.querySelector('[data-nova-poshta-building-input]')?.value.trim();

        return Boolean(streetRef && building);
    }

    const pointRef = root.querySelector('[data-nova-poshta-point-ref]')?.value.trim();

    return Boolean(pointRef);
};

const checkoutSectionComplete = (step) => {
    const section = document.querySelector(`[data-checkout-section="${step}"]`);
    if (!section) return false;
    if (step === 'order') return !section.querySelector('.checkout-order-empty');
    if (step === 'summary') return ['contacts', 'order', 'delivery', 'payment'].every(checkoutSectionComplete);
    if (step === 'delivery') {
        if (!checkoutNovaPoshtaReady()) return false;
    }
    if (step === 'payment') {
        const paymentMethods = section.querySelectorAll('input[name="payment_method"]');
        if (paymentMethods.length === 0) return false;
        if (![...paymentMethods].some((field) => field.checked)) return false;
    }

    return [...section.querySelectorAll('input, select, textarea')]
        .filter((field) => field.required && !field.disabled)
        .every((field) => field.checkValidity());
};

const checkoutStatusText = (step, complete) => {
    if (complete) {
        return {
            contacts: t('Контактні дані заповнено'),
            order: t('Товари готові до оформлення'),
            delivery: t('Дані доставки заповнено'),
            payment: t('Спосіб оплати обрано'),
            summary: t('Замовлення готове'),
        }[step];
    }

    return {
        contacts: t('Потрібно заповнити'),
        order: t('Додайте товари'),
        delivery: t('Потрібно заповнити'),
        payment: t('Потрібно обрати'),
        summary: t('Перевірте дані'),
    }[step];
};

const updateCheckoutStatuses = () => {
    if (!checkoutWizard) return;

    ['contacts', 'order', 'delivery', 'payment'].forEach((step) => {
        const complete = checkoutSectionComplete(step);
        const section = document.querySelector(`[data-checkout-section="${step}"]`);
        const button = document.querySelector(`[data-checkout-step-target="${step}"]`);
        const navStatus = button?.querySelector('[data-checkout-nav-status]');
        const sectionStatus = section?.querySelector('[data-checkout-section-status]');

        button?.classList.toggle('is-complete', complete);
        button?.classList.toggle('is-incomplete', !complete);
        section?.classList.toggle('is-complete', complete);
        section?.classList.toggle('is-incomplete', !complete);
        if (navStatus) navStatus.textContent = checkoutStatusText(step, complete);
        if (sectionStatus) sectionStatus.textContent = checkoutStatusText(step, complete);
    });

    const submit = document.querySelector('[data-checkout-submit]');
    if (submit) {
        const requiresLogin = checkoutAccountPrompt && !checkoutAccountPrompt.hidden;
        const ready = checkoutSectionComplete('summary') && submit.dataset.checkoutEmpty !== '1' && !requiresLogin;
        submit.classList.toggle('is-disabled', !ready);
        submit.setAttribute('aria-disabled', String(!ready));
        submit.textContent = requiresLogin ? t('Спочатку увійдіть') : t('Замовлення підтверджую');
    }
};

checkoutProgress?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-checkout-step-target]');
    if (!button) return;

    const section = document.querySelector(`[data-checkout-section="${button.dataset.checkoutStepTarget}"]`);
    section?.scrollIntoView({ behavior: 'smooth', block: 'start' });
});

checkoutWizard?.addEventListener('input', updateCheckoutStatuses);
checkoutWizard?.addEventListener('change', updateCheckoutStatuses);
checkoutWizard?.addEventListener('input', (event) => {
    if (!event.target.matches('[name="email"], [name="phone"]')) return;
    window.clearTimeout(checkoutAccountTimer);
    checkoutAccountTimer = window.setTimeout(checkCheckoutAccount, 450);
});
checkoutWizard?.addEventListener('focusout', (event) => {
    if (event.target.matches('[name="email"], [name="phone"]')) checkCheckoutAccount();
});
checkoutWizard?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-checkout-submit]');
    if (!button) return;

    if (checkoutAccountPrompt && !checkoutAccountPrompt.hidden) {
        checkoutAccountPrompt.classList.add('checkout-attention');
        checkoutAccountPrompt.scrollIntoView({ behavior: 'smooth', block: 'center' });
        window.setTimeout(() => checkoutAccountPrompt.classList.remove('checkout-attention'), 1800);
        return;
    }

    const steps = ['contacts', 'order', 'delivery', 'payment'];
    const incompleteStep = steps.find((step) => !checkoutSectionComplete(step));

    if (button.dataset.checkoutEmpty === '1' || incompleteStep) {
        const targetStep = button.dataset.checkoutEmpty === '1' ? 'order' : incompleteStep;
        const target = document.querySelector(`[data-checkout-section="${targetStep}"]`);
        target?.classList.add('checkout-attention');
        target?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        window.setTimeout(() => target?.classList.remove('checkout-attention'), 1800);

        const invalidField = target?.querySelector(':invalid:not(:disabled)');
        if (invalidField) {
            invalidField.focus({ preventScroll: true });
            invalidField.reportValidity?.();
            return;
        }

        if (incompleteStep === 'delivery' && !checkoutNovaPoshtaReady()) {
            const cityInput = target?.querySelector('[data-nova-poshta-city-input]');
            const pointInput = target?.querySelector('[data-nova-poshta-point-input]');
            const streetInput = target?.querySelector('[data-nova-poshta-street-input]');
            const buildingInput = target?.querySelector('[data-nova-poshta-building-input]');
            const cityRef = target?.querySelector('[data-nova-poshta-city-ref]')?.value.trim();
            const pointRef = target?.querySelector('[data-nova-poshta-point-ref]')?.value.trim();
            const streetRef = target?.querySelector('[data-nova-poshta-street-ref]')?.value.trim();
            const courierSelected = target?.querySelector('input[name="delivery_type"][value="nova_poshta_courier"]')?.checked;

            if (cityInput && !cityRef) {
                cityInput.setCustomValidity(t('Оберіть місто зі списку Нової Пошти.'));
                cityInput.reportValidity();
                cityInput.focus({ preventScroll: true });
                return;
            }

            if (courierSelected) {
                if (streetInput && !streetRef) {
                    streetInput.setCustomValidity(t('Оберіть вулицю зі списку Нової Пошти.'));
                    streetInput.reportValidity();
                    streetInput.focus({ preventScroll: true });
                    return;
                }

                if (buildingInput && !buildingInput.value.trim()) {
                    buildingInput.setCustomValidity(t('Вкажіть номер будинку.'));
                    buildingInput.reportValidity();
                    buildingInput.focus({ preventScroll: true });
                    return;
                }
            } else if (pointInput && !pointRef) {
                const postomat = target?.querySelector('input[name="delivery_type"][value="nova_poshta_postomat"]')?.checked;
                pointInput.setCustomValidity(t(postomat ? 'Оберіть поштомат зі списку.' : 'Оберіть відділення зі списку.'));
                pointInput.reportValidity();
                pointInput.focus({ preventScroll: true });
                return;
            }

            const novaPoshtaState = target?.querySelector('[data-nova-poshta-state]');
            if (novaPoshtaState && novaPoshtaPointsLoading) {
                novaPoshtaState.textContent = t('Завантажуємо точки доставки...');
                novaPoshtaState.hidden = false;
            }
        }

        return;
    }

    checkoutWizard.requestSubmit();
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-checkout-login]');
    if (!button || !checkoutAccountPrompt || !auth) return;

    saveCheckoutDraft();
    const loginEmail = checkoutAccountPrompt.dataset.loginEmail;
    const login = auth.querySelector('[data-auth-mode="login"] input[name="login"]');
    if (login && loginEmail) login.value = loginEmail;
    openPanel(auth);
    (loginEmail ? auth.querySelector('[data-auth-mode="login"] input[name="password"]') : login)?.focus();
});

updateCheckoutStatuses();

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-toggle-filter-options]');
    if (!toggle) return;
    const group = toggle.closest('[data-filter-group]');
    group.classList.add('is-expanded');
    refreshFilterGroup(group);
});

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-toggle-review-reply]');
    if (!toggle) return;
    const form = toggle.closest('.review-card').querySelector('.review-reply-form');
    if (!form) return;
    form.hidden = !form.hidden;
    if (!form.hidden) form.querySelector('input').focus();
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-expand-section]');
    if (!button) return;
    const section = button.closest('.product-info-card');
    section?.classList.add('is-expanded');
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-show-model-range]');
    if (!button) return;
    const range = button.closest('.model-range');
    range?.querySelectorAll('[data-model-range-extra]').forEach((item) => {
        item.hidden = false;
    });
    button.closest('.model-range-more')?.remove();
});

document.querySelectorAll('[data-expandable-section]').forEach((section) => {
    const content = section.querySelector('.product-info-content');
    const button = section.querySelector('[data-expand-section]');
    if (content && button && content.scrollHeight <= content.clientHeight + 2) {
        button.hidden = true;
    }
});

const catalogStickyControls = document.querySelector('.mobile-catalog-controls');
const catalogStickyHeader = document.querySelector('.main-header');
const catalogStickySearch = document.querySelector('.mobile-search');
const syncCatalogStickyOffset = () => {
    if (!catalogStickyControls || !catalogStickyHeader || !window.matchMedia('(max-width: 760px)').matches) {
        document.documentElement.style.removeProperty('--catalog-sticky-top');
        return;
    }

    const searchGap = catalogStickySearch ? Number.parseFloat(getComputedStyle(catalogStickySearch).marginBottom || '0') : 0;
    const stickyTop = Math.max(0, Math.ceil(catalogStickyHeader.getBoundingClientRect().bottom - searchGap));
    document.documentElement.style.setProperty('--catalog-sticky-top', `${stickyTop}px`);
};

if (catalogStickyControls && catalogStickyHeader) {
    syncCatalogStickyOffset();
    window.addEventListener('resize', syncCatalogStickyOffset, { passive: true });
    window.addEventListener('orientationchange', syncCatalogStickyOffset);

    if ('ResizeObserver' in window) {
        const resizeObserver = new ResizeObserver(syncCatalogStickyOffset);
        resizeObserver.observe(catalogStickyHeader);
        if (catalogStickySearch) resizeObserver.observe(catalogStickySearch);
    }
}

let mobileSheetPageScroll = null;
const rememberMobileSheetPageScroll = () => {
    if (mobileSheetPageScroll === null) {
        mobileSheetPageScroll = window.scrollY;
    }
};

document.querySelector('[data-toggle-mobile-filters]')?.addEventListener('click', () => {
    rememberMobileSheetPageScroll();
    document.querySelector('[data-mobile-sort]')?.classList.remove('is-open');
    document.querySelector('[data-mobile-filters]')?.classList.add('is-open');
    document.querySelector('[data-sheet-overlay]')?.classList.add('is-open');
    document.body.classList.add('panel-open');
});

document.querySelectorAll('[data-toggle-mobile-sort], [data-toggle-mobile-tags]').forEach((button) => {
    button.addEventListener('click', () => {
        rememberMobileSheetPageScroll();
        document.querySelector('[data-mobile-filters]')?.classList.remove('is-open');
        document.querySelector('[data-mobile-sort]')?.classList.add('is-open');
        document.querySelector('[data-sheet-overlay]')?.classList.add('is-open');
        document.body.classList.add('panel-open');
    });
});

const closeMobileSheets = () => {
    const restoreScroll = mobileSheetPageScroll;
    document.querySelector('[data-mobile-filters]')?.classList.remove('is-open');
    document.querySelector('[data-mobile-sort]')?.classList.remove('is-open');
    document.querySelector('[data-sheet-overlay]')?.classList.remove('is-open');
    document.body.classList.remove('panel-open');
    mobileSheetPageScroll = null;

    if (restoreScroll !== null) {
        requestAnimationFrame(() => window.scrollTo({ top: restoreScroll, behavior: 'auto' }));
    }
};

document.addEventListener('click', (event) => {
    if (event.target.closest('[data-close-mobile-sheets], [data-sheet-overlay]')) closeMobileSheets();
});

document.addEventListener('click', async (event) => {
    const paginationLink = event.target.closest('[data-catalog-pagination] a[href]');
    if (paginationLink && catalogResults) {
        event.preventDefault();
        try {
            await replaceCatalog(paginationLink.href);
        } catch (error) {
            showToast(error.message, 'error');
        }

        return;
    }

    const link = event.target.closest('[data-load-more]');
    if (!link) return;
    event.preventDefault();
    setElementLoading(link, true, t('Завантажуємо...'));
    document.querySelector('[data-product-grid]')?.classList.add('is-loading');
    try {
        const response = await fetch(relativeSiteUrl(link.href), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) throw new Error(t('Не вдалося завантажити наступні товари.'));
        const data = await response.json();
        const wrapper = document.createElement('div');
        wrapper.innerHTML = data.html;
        wrapper.querySelectorAll('[data-product-grid] .product-card').forEach((card) => document.querySelector('[data-product-grid]').append(card));
        updateProductButtons(window.storeState?.cartProductIds ?? []);
        updateListButtons(window.storeState?.favoriteIds ?? [], window.storeState?.comparisonIds ?? []);
        const next = wrapper.querySelector('[data-load-more]');
        link.parentElement.innerHTML = next ? next.outerHTML : '';
        const pagination = document.querySelector('[data-catalog-pagination]');
        const nextPagination = wrapper.querySelector('[data-catalog-pagination]');
        if (pagination && nextPagination) pagination.outerHTML = nextPagination.outerHTML;
        else if (pagination) pagination.remove();
        else if (nextPagination) document.querySelector('[data-catalog-seo]').insertAdjacentHTML('beforebegin', nextPagination.outerHTML);
        const seo = document.querySelector('[data-catalog-seo]');
        const nextSeo = wrapper.querySelector('[data-catalog-seo]');
        if (seo && nextSeo) seo.outerHTML = nextSeo.outerHTML;
        window.history.pushState({}, '', relativeSiteUrl(data.url));
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        setElementLoading(link, false);
        document.querySelector('[data-product-grid]')?.classList.remove('is-loading');
    }
});

window.addEventListener('popstate', () => {
    if (catalogResults) replaceCatalog(window.location.href, false).catch((error) => showToast(error.message, 'error'));
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeSearch();
        closePanels();
        closeMobileSheets();
    }
});

let toastTimer = null;
const toastDuration = 3600;

const restartToastProgress = () => {
    const progress = toast?.querySelector('[data-toast-progress]');
    if (!progress) return;

    progress.classList.remove('is-running');
    void progress.offsetWidth;
    progress.classList.add('is-running');
};

const hideToast = () => {
    if (!toast) return;

    toast.querySelector('[data-toast-progress]')?.classList.remove('is-running');
    toast.classList.remove('is-open');
};

const showToast = (message, type = 'success') => {
    if (!toast || !message) return;

    const textEl = toast.querySelector('[data-toast-text]');
    const labelEl = toast.querySelector('[data-toast-label]');

    if (textEl) {
        textEl.textContent = message;
    }

    if (labelEl) {
        labelEl.textContent = type === 'error'
            ? (toast.dataset.toastLabelError ?? 'Помилка')
            : (toast.dataset.toastLabelSuccess ?? 'Готово');
    }

    toast.classList.remove('is-success', 'is-error', 'error');
    toast.classList.add(type === 'error' ? 'is-error' : 'is-success');
    toast.classList.add('is-open');
    restartToastProgress();

    if (toastTimer) {
        window.clearTimeout(toastTimer);
    }

    toastTimer = window.setTimeout(hideToast, toastDuration);
};

toast?.querySelector('[data-toast-dismiss]')?.addEventListener('click', () => {
    if (toastTimer) {
        window.clearTimeout(toastTimer);
    }

    hideToast();
});

if (toast?.dataset.toastMessage) {
    showToast(toast.dataset.toastMessage, toast.dataset.toastType);
}

const jsonFetch = async (url, options = {}, timeout = 12000) => {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), timeout);

    try {
        const response = await fetch(url, { ...options, signal: controller.signal });
        const contentType = response.headers.get('content-type') ?? '';
        const data = contentType.includes('application/json') ? await response.json() : {};

        if (!response.ok) {
            const message = Object.values(data.errors ?? {}).flat()[0] ?? data.message ?? t('Не вдалося виконати дію.');
            throw new Error(message);
        }

        return data;
    } catch (error) {
        if (error.name === 'AbortError') throw new Error(t('Запит триває занадто довго. Спробуйте ще раз.'));
        throw error;
    } finally {
        window.clearTimeout(timer);
    }
};

const updateCartCounters = (count) => {
    document.querySelectorAll('[data-cart-count]').forEach((badge) => {
        badge.textContent = count;
        badge.classList.toggle('is-hidden', count === 0);
    });
    document.querySelectorAll('[data-cart-count-text]').forEach((badge) => {
        badge.textContent = count ? `(${count})` : '';
    });
};

const updateProductButtons = (productIds) => {
    document.querySelectorAll('[data-cart-product]').forEach((form) => {
        const button = form.querySelector('[data-cart-button]');
        if (!button) return;
        const inCart = productIds.includes(Number(form.dataset.cartProduct));
        button.textContent = inCart ? button.dataset.cartActiveLabel : button.dataset.cartDefaultLabel;
        button.classList.toggle('in-cart', inCart);
    });
};

const updateListCounters = (favoriteCount, comparisonCount) => {
    [
        ['favorites', favoriteCount],
        ['comparison', comparisonCount],
    ].forEach(([list, count]) => {
        document.querySelectorAll(`[data-${list}-count]`).forEach((badge) => {
            badge.textContent = count;
            badge.classList.toggle('is-hidden', count === 0);
        });
        document.querySelectorAll(`[data-${list}-count-text]`).forEach((badge) => {
            badge.textContent = count ? `(${count})` : '';
        });
    });
};

const updateListButtons = (favoriteIds, comparisonIds) => {
    document.querySelectorAll('[data-favorite-product]').forEach((button) => {
        const isFavorite = favoriteIds.includes(Number(button.dataset.favoriteProduct));
        button.textContent = isFavorite ? '♥' : '♡';
        button.classList.toggle('is-active', isFavorite);
    });
    document.querySelectorAll('[data-comparison-product]').forEach((button) => {
        button.classList.toggle('is-active', comparisonIds.includes(Number(button.dataset.comparisonProduct)));
    });
};

const updateStoreState = (key, value) => {
    window.storeState ??= {};
    window.storeState[key] = Array.isArray(value) ? value : [];
};

updateProductButtons(window.storeState?.cartProductIds ?? []);
updateListButtons(window.storeState?.favoriteIds ?? [], window.storeState?.comparisonIds ?? []);

const getCartDrawer = () => document.querySelector('[data-cart-drawer]');

const trackAddToCart = (form) => {
    if (typeof window.trackAnalyticsEvent !== 'function' || !form?.dataset.analyticsItem) return;

    let item;
    try {
        item = JSON.parse(form.dataset.analyticsItem);
    } catch {
        return;
    }

    const quantityInput = form.querySelector('input[name="quantity"]');
    const quantity = Math.max(1, Number(quantityInput?.value || item.quantity || 1));
    item.quantity = quantity;

    window.trackAnalyticsEvent('add_to_cart', {
        currency: 'UAH',
        value: Number((Number(item.price) * quantity).toFixed(2)),
        items: [item],
    });
};

const applyCartPayload = (data, { openDrawer = false } = {}) => {
    const cartDrawer = getCartDrawer();
    if (cartDrawer) cartDrawer.innerHTML = data.drawer;

    const cartPage = document.querySelector('[data-cart-page]');
    if (cartPage) cartPage.innerHTML = data.cart;

    const checkoutOrder = document.querySelector('[data-checkout-order]');
    if (checkoutOrder && data.checkoutOrder) checkoutOrder.innerHTML = data.checkoutOrder;

    const checkoutSummary = document.querySelector('[data-checkout-summary]');
    if (checkoutSummary && data.checkoutSummary) checkoutSummary.innerHTML = data.checkoutSummary;

    updateCheckoutStatuses();
    scheduleDeliveryPriceUpdate();
    updateCartCounters(data.count);
    updateStoreState('cartProductIds', data.productIds);
    updateProductButtons(data.productIds);
    showToast(data.message);

    if (openDrawer) {
        const drawer = getCartDrawer();
        if (drawer) openPanel(drawer);
    }
};

const submitCartForm = async (form, { openDrawer = false } = {}) => {
    if (!form || form.dataset.cartSubmitting === '1') return;

    const submitButton = form.querySelector('[data-cart-button], [data-cart-remove], button[type="submit"]');
    const submitLabel = submitButton?.textContent;
    let succeeded = false;

    form.dataset.cartSubmitting = '1';
    form.classList.add('is-loading');

    if (submitButton) {
        submitButton.disabled = true;
        if (submitButton.matches('[data-cart-button]')) submitButton.textContent = t('Додаємо...');
    }

    try {
        const data = await jsonFetch(form.action, {
            method: form.method,
            body: new FormData(form),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        applyCartPayload(data, { openDrawer });
        if (form.dataset.analyticsItem && form.method.toLowerCase() === 'post') {
            trackAddToCart(form);
        }
        succeeded = true;
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        delete form.dataset.cartSubmitting;
        form.classList.remove('is-loading');

        if (submitButton) {
            submitButton.disabled = false;
            if (!succeeded && submitButton.matches('[data-cart-button]') && submitLabel) {
                submitButton.textContent = submitLabel;
            }
        }
    }
};

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-quantity-change]');
    if (!button) return;

    event.preventDefault();

    const form = button.closest('[data-cart-quantity-form]');
    const input = form?.querySelector('input[name="quantity"]');
    if (!form || !input) return;

    const change = Number(button.dataset.quantityChange);
    const min = Number(input.min || 0);
    const max = Number(input.max || Infinity);
    const current = Number(input.value);
    const next = Math.max(min, Math.min(max, current + change));

    if (next === current) {
        if (change > 0 && Number.isFinite(max) && current >= max) {
            showToast(t('Більше цього товару на складі немає'));
        }

        return;
    }

    input.value = String(next);
    submitCartForm(form, { openDrawer: !form.closest('[data-cart-page]') });
});

document.addEventListener('change', (event) => {
    const input = event.target.closest('[data-cart-quantity-form] input[name="quantity"]');
    if (!input) return;

    const form = input.closest('[data-cart-quantity-form]');
    if (!form) return;

    submitCartForm(form, { openDrawer: !form.closest('[data-cart-page]') });
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-cart-form]');
    if (!form || form.matches('[data-cart-quantity-form]')) return;

    event.preventDefault();
    await submitCartForm(form, { openDrawer: !form.closest('[data-cart-page]') });
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-quick-order-form]');
    if (!form) return;

    event.preventDefault();
    const button = form.querySelector('button');
    setElementLoading(form, true, t('Оформлюємо...'));

    try {
        const response = await fetch(form.action, {
            method: form.method,
            body: new FormData(form),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json();
        if (!response.ok) throw new Error(Object.values(data.errors ?? {}).flat()[0] ?? data.message ?? t('Не вдалося створити швидке замовлення.'));
        window.location.assign(data.redirect);
    } catch (error) {
        showToast(error.message, 'error');
        setElementLoading(form, false);
    }
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-auth-form]');
    if (!form) return;

    event.preventDefault();
    const errors = form.querySelector('[data-auth-errors]');
    const button = form.querySelector('button[type="submit"], button:not([type])');
    errors.hidden = true;
    errors.innerHTML = '';
    const isRegister = form.dataset.authMode === 'register';
    setElementLoading(button, true, isRegister ? t('Створюємо...') : t('Перевіряємо...'));

    try {
        const response = await fetch(form.action, {
            method: form.method,
            body: new FormData(form),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const responseText = await response.text();
        let data = {};

        try {
            data = responseText ? JSON.parse(responseText) : {};
        } catch {
            data = {};
        }

        if (!response.ok) {
            const messages = Object.values(data.errors ?? {}).flat();
            let fallback = data.message;

            if (!fallback && response.status === 419) {
                fallback = t('Сесія сторінки завершилася. Оновіть сторінку та повторіть реєстрацію.');
            } else if (!fallback && response.status === 429) {
                fallback = t('Забагато спроб. Зачекайте хвилину та спробуйте ще раз.');
            } else if (!fallback) {
                fallback = isRegister
                    ? t('Не вдалося створити профіль. Помилка сервера :status.').replace(':status', response.status)
                    : t('Не вдалося увійти. Помилка сервера :status.').replace(':status', response.status);
            }

            errors.innerHTML = (messages.length ? messages : [fallback])
                .map((message) => `<p>${String(message).replace(/[&<>"']/g, (character) => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;',
                })[character])}</p>`)
                .join('');
            errors.hidden = false;
            const firstInvalidField = Object.keys(data.errors ?? {})[0];
            form.querySelector(`[name="${CSS.escape(firstInvalidField ?? (isRegister ? 'email' : 'login'))}"]`)?.focus();

            return;
        }

        if (!data.redirect) {
            throw new Error(t('Сервер не повернув адресу для переходу після реєстрації.'));
        }

        window.location.assign(data.redirect);
    } catch (error) {
        const message = error instanceof Error && error.message
            ? error.message
            : (isRegister ? t('Не вдалося створити профіль.') : t('Не вдалося виконати вхід.'));
        errors.innerHTML = `<p>${String(message).replace(/[&<>"']/g, (character) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        })[character])}</p>`;
        errors.hidden = false;
    } finally {
        setElementLoading(button, false);
    }
});

document.addEventListener('click', (event) => {
    const switchButton = event.target.closest('[data-auth-switch-mode]');
    if (!switchButton || !auth) return;

    const mode = switchButton.dataset.authSwitchMode;
    const loginForm = auth.querySelector('[data-auth-mode="login"]');
    const registerForm = auth.querySelector('[data-auth-mode="register"]');
    const isRegister = mode === 'register';

    loginForm.hidden = isRegister;
    registerForm.hidden = !isRegister;
    auth.querySelector('[data-auth-kicker]').textContent = isRegister ? t('Новий клієнт') : t('Особистий кабінет');
    auth.querySelector('[data-auth-title]').textContent = isRegister ? t('Створити профіль') : t('Увійти до Kubii');
    auth.querySelector('[data-auth-description]').textContent = isRegister
        ? t('Збережемо ваші замовлення та контактні дані в одному місці.')
        : t('Переглядайте історію замовлень і оформлюйте покупки швидше.');
    auth.querySelector('[data-auth-switch-text]').textContent = isRegister ? t('Вже є профіль?') : t('Ще немає профілю?');
    switchButton.dataset.authSwitchMode = isRegister ? 'login' : 'register';
    switchButton.textContent = isRegister ? t('Увійти') : t('Створити профіль');
    auth.querySelectorAll('[data-auth-errors]').forEach((errors) => {
        errors.hidden = true;
        errors.innerHTML = '';
    });
    (isRegister ? registerForm : loginForm).querySelector('input:not([type="hidden"])')?.focus();
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-product-list-form]');
    if (!form) return;

    event.preventDefault();
    setElementLoading(form, true, t('Оновлюємо...'));

    try {
        const response = await fetch(form.action, {
            method: form.method,
            body: new FormData(form),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error(t('Не вдалося оновити список.'));

        const data = await response.json();
        updateListCounters(data.favoriteCount, data.comparisonCount);
        updateStoreState('favoriteIds', data.favoriteIds);
        updateStoreState('comparisonIds', data.comparisonIds);
        updateListButtons(data.favoriteIds, data.comparisonIds);
        showToast(data.message);

        if (!data.added && form.closest('[data-list-page]')) {
            form.closest('[data-list-item]')?.remove();
        }
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        setElementLoading(form, false);
    }
});

const brandSearch = document.querySelector('[data-brand-search]');
brandSearch?.addEventListener('input', () => {
    const query = brandSearch.value.trim().toLowerCase();
    let visible = 0;

    document.querySelectorAll('[data-brand-item]').forEach((item) => {
        const matches = !query || (item.dataset.brandName || '').includes(query);
        item.hidden = !matches;
        if (matches) visible += 1;
    });

    document.querySelectorAll('.brand-letter-group').forEach((group) => {
        group.hidden = !group.querySelector('[data-brand-item]:not([hidden])');
    });

    const empty = document.querySelector('[data-brand-empty]');
    if (empty) empty.hidden = visible > 0;
});
