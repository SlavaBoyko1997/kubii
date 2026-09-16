<?php

namespace App\Console\Commands;

use App\Models\CatalogCacheLog;
use App\Models\Category;
use App\Services\CatalogSpecificationFacets;
use App\Services\ProductVariantGrouper;
use App\Support\CatalogCache;
use App\Support\CatalogCacheLogger;
use App\Support\CatalogCacheWarmProgress;
use App\Support\CategoryFilterSettingsNormalizer;
use App\Support\CategorySpecFilterSynchronizer;
use App\Support\HomeCatalogSelections;
use App\Support\Locale;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class WarmCatalogCache extends Command
{
    protected $signature = 'catalog:cache-warm
        {--fresh : Invalidate catalog cache before warming}
        {--all-pages : Pre-render every category page instead of only catalog entry pages}
        {--progress-key= : Redis progress identifier used by the admin panel}
        {--trigger=cli : Warm source: auto, manual, cli}';

    protected $description = 'Warm Redis/Laravel cache for public store pages, catalog data and category filter facets.';

    public function handle(
        CatalogCache $cache,
        CatalogSpecificationFacets $facets,
        CatalogCacheWarmProgress $progress,
        CatalogCacheLogger $logger,
        Kernel $kernel,
    ): int {
        @set_time_limit(0);
        $progressKey = $this->option('progress-key');
        $trigger = $this->normalizeTrigger((string) $this->option('trigger'));
        $fresh = (bool) $this->option('fresh');
        $startedAt = $this->resolveStartedAt($progress, $progressKey);
        $lock = $this->acquireWarmLock($progress);

        if ($lock === null) {
            $message = 'Інший процес оновлення кешу вже виконується.';

            if (is_string($progressKey) && $progressKey !== '') {
                $progress->fail($progressKey, $message);
            }

            $logger->recordWarmFailure($trigger, $message, $fresh, $startedAt);

            $this->components->warn('Another catalog cache refresh is already running.');

            return self::FAILURE;
        }

        $totalSteps = 0;
        $completedSteps = 0;

        try {
            if (is_string($progressKey) && $progressKey !== '') {
                $progress->update($progressKey, 'Готуємо нову версію кешу', 0, 1);
            }

            if ($this->option('fresh')) {
                $version = $cache->beginRefresh();
                app()->instance(CatalogCache::class, $cache);
                app(CategoryFilterSettingsNormalizer::class)->normalizeStoredSettings();
                app(ProductVariantGrouper::class)->syncCatalogVisibility();
                $updatedFilters = app(CategorySpecFilterSynchronizer::class)->syncAll();
                $this->components->info("Category spec filters synced for {$updatedFilters} categories.");
                $this->components->info("Warming hidden catalog cache version {$version}.");
            }

            $originalLocale = app()->getLocale();

            foreach (Locale::SUPPORTED as $locale) {
                app()->setLocale($locale);
                $cache->menu();
                $this->warmHome($cache);
                $cache->brands();
                $this->components->info("Catalog menu, home and brands warmed for {$locale}.");
            }

            $categories = Category::query()
                ->where('is_active', true)
                ->where('name', '!=', 'ІБІС Зброя')
                ->with('childrenRecursive')
                ->orderBy('id')
                ->get();

            $pageCategories = $this->option('all-pages')
                ? $categories
                : $categories->filter(fn (Category $category): bool => $cache->categoryHasProducts($category->id));
            $paths = collect(Locale::SUPPORTED)->flatMap(function (string $locale) use ($pageCategories): array {
                app()->setLocale($locale);

                return [
                    localized_route('pages.show', 'about', false),
                    localized_route('pages.show', 'delivery', false),
                    localized_route('pages.show', 'returns', false),
                    localized_route('pages.show', 'warranty', false),
                    localized_route('pages.show', 'offer', false),
                    localized_route('pages.show', 'privacy', false),
                    localized_route('pages.show', 'contacts', false),
                    ...$pageCategories->map(fn (Category $category): string => $category->catalogUrl(absolute: false, locale: $locale))->all(),
                ];
            })->unique()->values();

            $brandPaths = collect(Locale::SUPPORTED)->flatMap(function (string $locale) use ($cache): array {
                app()->setLocale($locale);

                return [
                    localized_route('brands.index', [], false),
                    ...collect($cache->brands())
                        ->map(fn (array $brand): string => localized_route('brands.show', $brand['slug'], false))
                        ->all(),
                ];
            })->unique()->values();
            $paths = $paths->concat($brandPaths)->unique()->values();
            $brandFilterItems = collect(Locale::SUPPORTED)->flatMap(function (string $locale) use ($cache) {
                app()->setLocale($locale);

                return collect($cache->brands())->map(fn (array $brand): array => [
                    'locale' => $locale,
                    'name' => $brand['name'],
                    'path' => localized_route('brands.show', $brand['slug'], false),
                ]);
            });
            $totalSteps = 4 + ($pageCategories->count() * count(Locale::SUPPORTED) * 2) + $brandFilterItems->count() + $paths->count();
            $completedSteps = 4;

            if (is_string($progressKey) && $progressKey !== '') {
                $progress->update($progressKey, 'Формуємо фільтри категорій', $completedSteps, $totalSteps);
            }

            $items = collect(Locale::SUPPORTED)->flatMap(fn (string $locale) => $pageCategories->map(fn (Category $category): array => [
                'locale' => $locale,
                'category' => $category,
            ]));
            $bar = $this->output->createProgressBar($items->count());
            $bar->start();

            foreach ($items as $item) {
                app()->setLocale($item['locale']);
                $category = $item['category'];
                $facets->warmBaseFacets($category, $cache);
                $cache->categoryShowcase($category->id);
                $bar->advance();
                $completedSteps++;

                if (is_string($progressKey) && $progressKey !== '') {
                    $progress->update(
                        $progressKey,
                        'Фільтри категорій ['.$item['locale'].']: '.$category->name,
                        $completedSteps,
                        $totalSteps,
                    );
                }
            }

            $bar->finish();
            $this->newLine(2);
            $this->components->info("Catalog facet cache warmed for {$items->count()} scopes.");

            if (is_string($progressKey) && $progressKey !== '') {
                $progress->update($progressKey, 'Готуємо HTML фільтрів категорій', $completedSteps, $totalSteps);
            }

            $bar = $this->output->createProgressBar($items->count());
            $bar->start();

            foreach ($items as $item) {
                app()->setLocale($item['locale']);
                $category = $item['category'];
                $path = $category->catalogUrl(['filters_only' => 1], absolute: false, locale: $item['locale']);
                $response = $this->warmInternalRequest($kernel, $path, 'application/json');

                if ($response->getStatusCode() >= 400) {
                    $this->newLine();
                    $this->components->warn("Skipped filters {$path}: HTTP {$response->getStatusCode()}");
                }

                $bar->advance();
                $completedSteps++;

                if (is_string($progressKey) && $progressKey !== '') {
                    $progress->update(
                        $progressKey,
                        'HTML фільтрів ['.$item['locale'].']: '.$category->name,
                        $completedSteps,
                        $totalSteps,
                    );
                }
            }

            $bar->finish();
            $this->newLine(2);
            $this->components->info("Catalog filter HTML cache warmed for {$items->count()} scopes.");

            if ($brandFilterItems->isNotEmpty()) {
                if (is_string($progressKey) && $progressKey !== '') {
                    $progress->update($progressKey, 'Готуємо HTML фільтрів брендів', $completedSteps, $totalSteps);
                }

                $bar = $this->output->createProgressBar($brandFilterItems->count());
                $bar->start();

                foreach ($brandFilterItems as $item) {
                    app()->setLocale($item['locale']);
                    $path = $item['path'].(str_contains($item['path'], '?') ? '&' : '?').'filters_only=1';
                    $response = $this->warmInternalRequest($kernel, $path, 'application/json');

                    if ($response->getStatusCode() >= 400) {
                        $this->newLine();
                        $this->components->warn("Skipped brand filters {$path}: HTTP {$response->getStatusCode()}");
                    }

                    $bar->advance();
                    $completedSteps++;

                    if (is_string($progressKey) && $progressKey !== '') {
                        $progress->update(
                            $progressKey,
                            'HTML фільтрів бренда ['.$item['locale'].']: '.$item['name'],
                            $completedSteps,
                            $totalSteps,
                        );
                    }
                }

                $bar->finish();
                $this->newLine(2);
                $this->components->info("Brand filter HTML cache warmed for {$brandFilterItems->count()} scopes.");
            }

            $this->components->info('Warming public pages and catalog result caches.');
            $bar = $this->output->createProgressBar($paths->count());
            $bar->start();

            foreach ($paths as $path) {
                $response = $this->warmInternalRequest($kernel, $path, 'text/html');

                if ($response->getStatusCode() >= 400) {
                    $this->newLine();
                    $this->components->warn("Skipped {$path}: HTTP {$response->getStatusCode()}");
                }

                $bar->advance();
                $completedSteps++;

                if (is_string($progressKey) && $progressKey !== '') {
                    $progress->update(
                        $progressKey,
                        'Сторінки каталогу: '.$path,
                        $completedSteps,
                        $totalSteps,
                    );
                }
            }

            $bar->finish();
            $this->newLine(2);
            $this->components->info("Public cache warmed for {$paths->count()} URLs.");

            if ($this->option('fresh')) {
                $cache->publishRefresh();
                $this->components->info('Catalog cache version switched to the freshly warmed cache.');
            }

            if (is_string($progressKey) && $progressKey !== '') {
                $progress->complete($progressKey);
            }

            app()->setLocale($originalLocale);

            $logger->recordWarmSuccess($trigger, $fresh, $completedSteps, $totalSteps, $startedAt);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if (is_string($progressKey) && $progressKey !== '') {
                $progress->fail($progressKey, $exception->getMessage());
            }

            $logger->recordWarmFailure($trigger, $exception->getMessage(), $fresh, $startedAt);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function resolveStartedAt(CatalogCacheWarmProgress $progress, mixed $progressKey): Carbon
    {
        if (! is_string($progressKey) || $progressKey === '') {
            return now();
        }

        $status = $progress->status($progressKey);
        $startedAt = $status['started_at'] ?? null;

        if (is_string($startedAt) && $startedAt !== '') {
            return Carbon::parse($startedAt);
        }

        return now();
    }

    private function normalizeTrigger(string $trigger): string
    {
        return match ($trigger) {
            CatalogCacheLog::TRIGGER_AUTO,
            CatalogCacheLog::TRIGGER_MANUAL,
            CatalogCacheLog::TRIGGER_CLI,
            CatalogCacheLog::TRIGGER_SYSTEM => $trigger,
            default => CatalogCacheLog::TRIGGER_CLI,
        };
    }

    private function warmHome(CatalogCache $cache): void
    {
        $cache->remember('home:v6', fn (): array => app(HomeCatalogSelections::class)->resolve());
        $cache->homeRootCategories();
    }

    private function warmInternalRequest(Kernel $kernel, string $path, string $accept): \Symfony\Component\HttpFoundation\Response
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $host = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'http';

        $request = Request::create($path, 'GET', server: [
            'HTTP_ACCEPT' => $accept,
            'HTTP_HOST' => $host,
            'SERVER_NAME' => $host,
            'HTTPS' => $scheme === 'https' ? 'on' : 'off',
            'HTTP_X_REQUESTED_WITH' => $accept === 'application/json' ? 'XMLHttpRequest' : '',
        ]);

        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    private function acquireWarmLock(CatalogCacheWarmProgress $progress): ?Lock
    {
        $lock = Cache::lock('catalog:cache-warm:lock', 1800);

        if ($lock->get()) {
            return $lock;
        }

        if ($progress->isRunning()) {
            return null;
        }

        $lock->forceRelease();

        return $lock->get() ? $lock : null;
    }
}
