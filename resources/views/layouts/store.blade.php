<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @php
        $resolvedSeoMeta = $seoMeta ?? app(\App\Services\SeoMeta::class)->generic(
            trim($__env->yieldContent('title', 'Kubii')),
        );
    @endphp
    <title>{{ isset($seoMeta) ? $resolvedSeoMeta['title'] : trim($__env->yieldContent('title', 'Kubii')).' - '.__('спорядження для туризму та рибалки') }}</title>
    @if(isset($seoMeta))
        <meta name="description" content="{{ $resolvedSeoMeta['description'] }}">
    @endif
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="catalog-filter-click-url" content="{{ localized_route('catalog.filter-click') }}">
    <meta name="catalog-menu-url" content="{{ localized_route('catalog.menu') }}">
    <meta name="search-suggestions-url" content="{{ localized_route('search.suggestions') }}">
    <meta name="search-click-url" content="{{ localized_route('search.click') }}">
    @php
        $seoRouteName = preg_replace('/\.ru$/', '', (string) request()->route()?->getName());
        $noindexRouteNames = [
            'search.index', 'cart.index', 'favorites.index', 'comparison.index',
            'checkout.create', 'checkout.success', 'login', 'register',
            'cart.merge.show', 'account.index',
        ];
    @endphp
    @if(! empty($seoRobots))
        <meta name="robots" content="{{ $seoRobots }}">
    @else
        <meta name="robots" content="max-image-preview:large">
        @if(in_array($seoRouteName, $noindexRouteNames, true))
            <meta name="robots" content="noindex, follow">
        @endif
    @endif
    @php
        $resolvedCanonical = app(\App\Services\SeoCanonical::class)->make(
            $canonicalUrl ?? $resolvedSeoMeta['url'] ?? request()->url(),
        );
    @endphp
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.svg') }}">
    <link rel="canonical" href="{{ $resolvedCanonical['url'] }}">
    @foreach($resolvedCanonical['alternates'] as $locale => $url)
        <link rel="alternate" hreflang="{{ $locale }}" href="{{ $url }}">
    @endforeach
    <x-seo-meta :meta="$resolvedSeoMeta" />
    <x-google-analytics />
    <x-meta-pixel />
    @php
        $storeI18nKeys = [
            'Ціна уточнюється', 'Нічого точного не знайшли. Спробуйте коротший запит.', 'Розпізнано як:',
            'Категорії', 'Товари', 'Показати всі результати', 'Введіть щонайменше 2 символи',
            'Шукаємо...', 'Не вдалося завантажити підказки. Натисніть Enter для пошуку.',
            'Завантажуємо...', 'Не вдалося завантажити товари.',
            'Ці email і телефон уже прив’язані до профілю. Увійдіть, щоб продовжити.',
            'Цей email уже прив’язаний до профілю. Увійдіть, щоб продовжити.',
            'Цей номер телефону уже прив’язаний до профілю. Увійдіть, щоб продовжити.',
            'Контактні дані заповнено', 'Товари готові до оформлення', 'Дані доставки заповнено',
            'Спосіб оплати обрано', 'Замовлення готове', 'Потрібно заповнити', 'Додайте товари',
            'Потрібно обрати', 'Перевірте дані', 'Спочатку увійдіть', 'Замовлення підтверджую',
            'Не вдалося завантажити наступні товари.', 'Не вдалося виконати дію.',
            'Запит триває занадто довго. Спробуйте ще раз.', 'Додаємо...', 'Оформлюємо...',
            'Не вдалося створити швидке замовлення.', 'Створюємо...', 'Перевіряємо...',
            'Сесія сторінки завершилася. Оновіть сторінку та повторіть реєстрацію.',
            'Забагато спроб. Зачекайте хвилину та спробуйте ще раз.',
            'Не вдалося створити профіль. Помилка сервера :status.',
            'Не вдалося увійти. Помилка сервера :status.',
            'Сервер не повернув адресу для переходу після реєстрації.',
            'Не вдалося створити профіль.', 'Не вдалося виконати вхід.', 'Новий клієнт',
            'Завантажуємо категорії...', 'Не вдалося завантажити каталог.',
            'Особистий кабінет', 'Створити профіль', 'Увійти до Kubii',
            'Збережемо ваші замовлення та контактні дані в одному місці.',
            'Переглядайте історію замовлень і оформлюйте покупки швидше.',
            'Вже є профіль?', 'Ще немає профілю?', 'Увійти', 'Оновлюємо...',
            'Не вдалося оновити список.',
            'Введіть щонайменше 2 символи назви міста.', 'Шукаємо міста...',
            'Міст не знайдено.', 'Не вдалося завантажити міста Нової Пошти.',
            'Завантажуємо відділення...', 'Завантажуємо поштомати...',
            'Оберіть відділення', 'Оберіть поштомат', 'Відділення', 'Поштомат',
            'Оберіть вантажне відділення', 'Показані лише відділення, що приймають габарити вашого замовлення',
            'Спочатку оберіть місто', 'У вибраному місті точок цього типу не знайдено.',
            'Не вдалося завантажити точки Нової Пошти.',
            'Оберіть місто зі списку Нової Пошти.',
            'Оберіть відділення зі списку.', 'Оберіть поштомат зі списку.',
            'Введіть номер або адресу відділення', 'Введіть номер або адресу поштомату',
            'Шукайте за номером відділення, вулицею або адресою',
            'Шукайте за номером поштомату, вулицею або адресою',
            'Нічого не знайдено. Спробуйте номер або частину адреси.',
            'Знайдено точок: :count', 'Обрано', 'Змінити',
            'Завантажуємо точки доставки...',
            'Розраховуємо...', 'Орієнтовна вартість: :price', 'Безкоштовно', 'За тарифом перевізника',
            'Більше цього товару на складі немає',
            'Характеристики скопійовано',
            'Не вдалося скопіювати характеристики',
            'Немає характеристик для копіювання',
        ];
        $storeI18n = [];

        foreach ($storeI18nKeys as $storeI18nKey) {
            $storeI18n[$storeI18nKey] = __($storeI18nKey);
        }

        $storeState = [
            'cartProductIds' => $cartProductIds,
            'favoriteIds' => $favoriteIds,
            'comparisonIds' => $comparisonIds,
        ];
    @endphp
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        window.storeI18n = @json($storeI18n);
        window.storeState = @json($storeState);
    </script>
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="topbar">
        <div class="container">
            <div class="top-links"><a href="{{ localized_route('pages.show', 'about') }}">{{ __('Про нас') }}</a><a href="{{ localized_route('pages.show', 'delivery') }}">{{ __('Доставка і оплата') }}</a><a href="{{ localized_route('pages.show', 'warranty') }}">{{ __('Гарантія') }}</a><a href="{{ localized_route('blog.index') }}">{{ __('Блог') }}</a><a href="{{ localized_route('brands.index') }}">{{ __('Бренди') }}</a><a href="{{ localized_route('pages.show', 'contacts') }}">{{ __('Контакти') }}</a></div>
            <div class="top-links">@if(\App\Support\StoreInfo::email())<a href="mailto:{{ \App\Support\StoreInfo::email() }}">{{ \App\Support\StoreInfo::email() }}</a>@endif @include('store._social-links', ['class' => 'topbar-social'])<a class="language-link" href="{{ \App\Support\Locale::switchUrl(app()->getLocale() === 'uk' ? 'ru' : 'uk') }}" aria-label="{{ app()->getLocale() === 'uk' ? 'Перейти на російську' : 'Перейти на українську' }}">@if(app()->getLocale() === 'uk')<i class="ukraine-flag" aria-hidden="true"></i><b>UK</b>@else<b>RU</b>@endif</a></div>
        </div>
    </div>
    <header class="main-header">
        <div class="container">
            <button class="mobile-only menu-trigger" type="button" data-open-catalog aria-label="{{ __('Каталог') }}"><x-heroicon-o-bars-3 /></button>
            <a class="brand" href="{{ localized_route('home') }}" aria-label="BASH">@include('store._logo')</a>
            <button class="catalog-button" type="button" data-open-catalog>{{ __('Каталог товарів') }} ☰</button>
            <form class="search" action="{{ localized_route('search.index') }}" method="GET" data-smart-search>
                <input name="q" value="{{ request('q') }}" placeholder="{{ __('Пошук товарів...') }}" autocomplete="off" data-smart-search-input>
                <button aria-label="{{ __('Знайти') }}"><x-heroicon-o-magnifying-glass /></button>
            </form>
            <div class="header-actions">
                <a class="language-link mobile-language-link" href="{{ \App\Support\Locale::switchUrl(app()->getLocale() === 'uk' ? 'ru' : 'uk') }}" aria-label="{{ app()->getLocale() === 'uk' ? 'Перейти на російську' : 'Перейти на українську' }}">@if(app()->getLocale() === 'uk')<i class="ukraine-flag" aria-hidden="true"></i><b>UK</b>@else<b>RU</b>@endif</a>
                @auth
                    <a class="header-action" href="{{ localized_route('account.index') }}"><x-heroicon-o-user class="action-icon" /><span>{{ auth()->user()->fullName() }}</span></a>
            @else
                <button class="header-action" type="button" data-open-auth><x-heroicon-o-user class="action-icon" /><span>{{ __('Увійти') }}</span></button>
            @endauth
            <a class="header-action list-link" href="{{ localized_route('favorites.index') }}"><x-heroicon-o-heart class="action-icon" /><span>{{ __('Обране') }}</span><b data-favorites-count class="{{ $favoriteCount ? '' : 'is-hidden' }}">{{ $favoriteCount }}</b></a>
            <a class="header-action list-link" href="{{ localized_route('comparison.index') }}"><x-heroicon-o-arrows-right-left class="action-icon" /><span>{{ __('Порівняння') }}</span><b data-comparison-count class="{{ $comparisonCount ? '' : 'is-hidden' }}">{{ $comparisonCount }}</b></a>
            <button class="header-action cart-link" type="button" data-open-cart><x-heroicon-o-shopping-cart class="action-icon" /><span>{{ __('Кошик') }}</span><b data-cart-count class="{{ $cartCount ? '' : 'is-hidden' }}">{{ $cartCount }}</b></button>
            </div>
        </div>
        @if(($navCategories ?? collect())->isNotEmpty())
            <nav class="catalog-nav" data-catalog-nav aria-label="{{ __('Каталог') }}">
                <div class="container">
                    @foreach($navCategories as $category)
                        <a href="{{ $category['url'] }}" data-mega-root="{{ $category['id'] }}">{{ $category['name'] }}</a>
                    @endforeach
                </div>
            </nav>
        @endif
        <form class="mobile-search" action="{{ localized_route('search.index') }}" method="GET" data-smart-search>
            <input name="q" value="{{ request('q') }}" placeholder="{{ __('Пошук товарів...') }}" aria-label="{{ __('Пошук товарів') }}" autocomplete="off" data-smart-search-input>
            <button type="submit" aria-label="{{ __('Знайти') }}"><x-heroicon-o-magnifying-glass /></button>
        </form>
    </header>
    <section class="search-suggestions" data-search-suggestions aria-label="{{ __('Пошукові підказки') }}" hidden>
        <div class="search-suggestions-state" data-search-state>{{ __('Введіть назву товару, категорію, бренд або артикул') }}</div>
        <div data-search-results></div>
    </section>

    <div class="overlay" data-overlay></div>
    <div class="image-lightbox" data-image-lightbox role="dialog" aria-modal="true" aria-label="{{ __('Перегляд фото') }}">
        <button type="button" class="image-lightbox-close" data-close-image-lightbox aria-label="{{ __('Закрити') }}">×</button>
        <button type="button" class="image-lightbox-arrow image-lightbox-prev" data-image-lightbox-prev aria-label="{{ __('Попереднє фото') }}">‹</button>
        <button type="button" class="image-lightbox-arrow image-lightbox-next" data-image-lightbox-next aria-label="{{ __('Наступне фото') }}">›</button>
        <div class="image-lightbox-stage">
            <img src="" alt="" data-image-lightbox-img>
        </div>
        <span class="image-lightbox-counter" data-image-lightbox-counter><b data-image-lightbox-current>1</b> / <b data-image-lightbox-total>1</b></span>
    </div>
    <section class="catalog-popup" data-catalog-popup>
        <div class="popup-head"><div><span>{{ __('Оберіть напрямок') }}</span><h2>{{ __('Каталог товарів') }}</h2></div><button type="button" data-close-catalog aria-label="{{ __('Закрити') }}">×</button></div>
        <div class="popup-categories" data-catalog-menu>
            <div class="catalog-menu-state">{{ __('Завантажуємо категорії...') }}</div>
        </div>
        <a class="popup-all" href="{{ $catalogEntryUrl }}">{{ __('Перейти до каталогу') }}</a>
    </section>
    <aside class="cart-drawer" data-cart-drawer>
        @include('store._cart-drawer')
    </aside>
    <section class="quick-order-popup" data-quick-order-popup>
        <div class="popup-head"><div><span>{{ __('Замовлення в один клік') }}</span><h2>{{ __('Швидке замовлення') }}</h2></div><button type="button" data-close-quick-order aria-label="{{ __('Закрити') }}">×</button></div>
        <p>{{ __('Залиште номер телефону. Ми передзвонимо, уточнимо деталі та допоможемо з доставкою.') }}</p>
        <strong data-quick-order-product></strong>
        <form action="{{ localized_route('checkout.quick') }}" method="POST" data-quick-order-form>
            @csrf
            <input type="hidden" name="product_id">
            <input type="hidden" name="quantity" value="1">
            <label>{{ __('Телефон') }}<input type="tel" name="phone" value="{{ auth()->user()?->phone }}" inputmode="tel" autocomplete="tel" placeholder="+380 99 123 45 67" @auth readonly class="readonly-input" @endauth required></label>
            <button class="primary-button">{{ __('Замовити дзвінок') }}</button>
        </form>
    </section>
    @guest
        <section class="auth-popup" data-auth-popup>
            <div class="popup-head"><div><span data-auth-kicker>{{ __('Особистий кабінет') }}</span><h2 data-auth-title>{{ __('Увійти до Kubii') }}</h2></div><button type="button" data-close-auth aria-label="{{ __('Закрити') }}">×</button></div>
            <p data-auth-description>{{ __('Переглядайте історію замовлень і оформлюйте покупки швидше.') }}</p>
            <x-auth.google-button />
            <div class="auth-divider">{{ __('або') }}</div>
            <form action="{{ localized_route('login.store') }}" method="POST" data-auth-form data-auth-mode="login">@csrf
                <div class="validation-errors auth-form-errors" data-auth-errors hidden></div>
                <label>{{ __('Email або номер телефону') }}<input name="login" value="{{ session('checkout_login_email') }}" inputmode="email" autocomplete="username" placeholder="{{ __('example@email.com або +380 99 123 45 67') }}" required></label>
                <label>{{ __('Пароль') }}<input type="password" name="password" required></label>
                <label class="check-label"><input type="checkbox" name="remember" value="1"> {{ __("Запам'ятати мене") }}</label>
                <button class="primary-button">{{ __('Увійти') }}</button>
            </form>
            <form action="{{ localized_route('register.store') }}" method="POST" data-auth-form data-auth-mode="register" hidden>@csrf
                <div class="validation-errors auth-form-errors" data-auth-errors hidden></div>
                <div class="auth-register-grid">
                    <label>{{ __('Прізвище') }}<input name="last_name" autocomplete="family-name" required></label>
                    <label>{{ __("Ім'я") }}<input name="first_name" autocomplete="given-name" required></label>
                </div>
                <label>{{ __('По батькові') }}<input name="patronymic" required></label>
                <label>Email<input type="email" name="email" autocomplete="email" required></label>
                <label>{{ __('Телефон') }}<input type="tel" name="phone" inputmode="tel" autocomplete="tel" placeholder="+380 99 123 45 67" required></label>
                <div class="auth-register-grid">
                    <label>{{ __('Пароль') }}<input type="password" name="password" autocomplete="new-password" minlength="8" required></label>
                    <label>{{ __('Повторіть пароль') }}<input type="password" name="password_confirmation" autocomplete="new-password" minlength="8" required></label>
                </div>
                <button class="primary-button">{{ __('Створити профіль') }}</button>
            </form>
            <div class="auth-switch" data-auth-switch>
                <span data-auth-switch-text>{{ __('Ще немає профілю?') }}</span>
                <button type="button" data-auth-switch-mode="register">{{ __('Створити профіль') }}</button>
            </div>
        </section>
    @endguest
    <div
        class="toast"
        data-toast
        role="status"
        aria-live="polite"
        aria-atomic="true"
        data-toast-message="{{ session('success') ?? session('error') }}"
        data-toast-type="{{ session('error') ? 'error' : 'success' }}"
        data-toast-label-success="{{ __('Готово') }}"
        data-toast-label-error="{{ __('Помилка') }}"
    >
        <div class="toast-card">
            <div class="toast-body">
                <span class="toast-icon" data-toast-icon aria-hidden="true">
                    <svg class="toast-icon-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    <svg class="toast-icon-error" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><circle cx="12" cy="16.5" r=".9" fill="currentColor" stroke="none"/></svg>
                </span>
                <div class="toast-copy">
                    <strong class="toast-label" data-toast-label></strong>
                    <p class="toast-text" data-toast-text></p>
                </div>
                <button type="button" class="toast-dismiss" data-toast-dismiss aria-label="{{ __('Закрити') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
            <span class="toast-progress" data-toast-progress aria-hidden="true"><i></i></span>
        </div>
    </div>
    <div class="global-loader" data-global-loader aria-hidden="true"><span></span><strong>{{ __('Завантажуємо...') }}</strong></div>

    <main>@yield('content')</main>

    <footer class="site-footer">
        <div class="container footer-grid">
            <div><a class="footer-brand" href="{{ localized_route('home') }}" aria-label="BASH">@include('store._logo')</a><p class="footer-tagline">{{ __('Спорядження для туризму, кемпінгу та риболовлі з доставкою по Україні.') }}</p><p>@if(\App\Support\StoreInfo::email())<a href="mailto:{{ \App\Support\StoreInfo::email() }}">{{ \App\Support\StoreInfo::email() }}</a><br>@endif{{ \App\Support\StoreInfo::schedule() }}</p><p class="footer-social-label">{{ __('Ми в соцмережах') }}</p>@include('store._social-links', ['class' => 'footer-social'])</div>
            @if(($footerCategories ?? collect())->isNotEmpty())
                <div>
                    <h3>{{ __('Категорії товарів') }}</h3>
                    @foreach($footerCategories as $footerCategory)
                        <a href="{{ $footerCategory['url'] }}">{{ $footerCategory['name'] }}</a>
                    @endforeach
                </div>
            @endif
            <div><h3>{{ __('Покупцям') }}</h3><a href="{{ localized_route('pages.show', 'delivery') }}">{{ __('Доставка і оплата') }}</a><a href="{{ localized_route('pages.show', 'returns') }}">{{ __('Обмін і повернення') }}</a><a href="{{ localized_route('pages.show', 'warranty') }}">{{ __('Гарантія') }}</a><a href="{{ $catalogEntryUrl }}">{{ __('Каталог товарів') }}</a><a href="{{ localized_route('brands.index') }}">{{ __('Бренди') }}</a></div>
            <div><h3>{{ __('Інформація') }}</h3><a href="{{ localized_route('pages.show', 'about') }}">{{ __('Про компанію') }}</a><a href="{{ localized_route('blog.index') }}">{{ __('Блог') }}</a><a href="{{ localized_route('pages.show', 'contacts') }}">{{ __('Контакти та реквізити') }}</a><a href="{{ localized_route('pages.show', 'offer') }}">{{ __('Публічна оферта') }}</a><a href="{{ localized_route('pages.show', 'privacy') }}">{{ __('Політика конфіденційності') }}</a></div>
            <div><h3>{{ __('Особистий кабінет') }}</h3>@auth<a href="{{ localized_route('account.index') }}">{{ __('Мої замовлення') }}</a>@else<a href="{{ localized_route('login') }}">{{ __('Увійти') }}</a><a href="{{ localized_route('register') }}">{{ __('Зареєструватися') }}</a>@endauth</div>
        </div>
        <div class="container footer-bottom"><span>© {{ date('Y') }} Kubii. {{ __('Усі права захищені.') }}</span><span>{{ __('Інформація на сайті є актуальною на момент перегляду. Остаточна сума замовлення формується з урахуванням обраного способу доставки та оплати.') }}</span></div>
    </footer>

    <nav class="bottom-nav">
        <a href="{{ localized_route('home') }}"><x-heroicon-o-home class="bottom-nav-icon" /><span>{{ __('Головна') }}</span></a>
        <button type="button" data-open-catalog><x-heroicon-o-squares-2x2 class="bottom-nav-icon" /><span>{{ __('Каталог') }}</span></button>
    <a href="{{ localized_route('favorites.index') }}"><x-heroicon-o-heart class="bottom-nav-icon" /><span>{{ __('Обране') }} <i data-favorites-count-text>@if($favoriteCount)({{ $favoriteCount }})@endif</i></span></a>
    <a href="{{ localized_route('comparison.index') }}"><x-heroicon-o-arrows-right-left class="bottom-nav-icon" /><span>{{ __('Порівняння') }} <i data-comparison-count-text>@if($comparisonCount)({{ $comparisonCount }})@endif</i></span></a>
    <button type="button" data-open-cart><x-heroicon-o-shopping-cart class="bottom-nav-icon" /><span>{{ __('Кошик') }} <i data-cart-count-text>@if($cartCount)({{ $cartCount }})@endif</i></span></button>
    </nav>
    @stack('analytics')
</body>
</html>
