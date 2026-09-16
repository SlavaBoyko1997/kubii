<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Product;
use App\Support\Locale;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SitemapController extends Controller
{
    public function robots(): Response
    {
        $content = config('seo.indexing_enabled')
            ? $this->productionRobots()
            : "User-agent: *\nDisallow: /\n";

        return response($content, 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function index(): Response
    {
        $productSitemaps = (int) ceil($this->indexableProducts()->count() / $this->productChunk());
        $urls = [
            route('sitemap.static'),
            route('sitemap.blog'),
            route('sitemap.categories'),
        ];

        for ($page = 1; $page <= $productSitemaps; $page++) {
            $urls[] = route('sitemap.products', $page);
        }

        return $this->xml(
            '<?xml version="1.0" encoding="UTF-8"?>'."\n".
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n".
            collect($urls)->map(fn (string $url): string => "  <sitemap><loc>{$this->escape($url)}</loc><lastmod>".now()->toDateString().'</lastmod></sitemap>')->implode("\n").
            "\n</sitemapindex>"
        );
    }

    public function static(): Response
    {
        $pages = [
            ['home', [], 'daily', '1.0'],
            ['blog.index', [], 'daily', '0.7'],
            ['pages.show', 'about', 'monthly', '0.5'],
            ['pages.show', 'delivery', 'monthly', '0.5'],
            ['pages.show', 'returns', 'monthly', '0.5'],
            ['pages.show', 'warranty', 'monthly', '0.5'],
            ['pages.show', 'offer', 'monthly', '0.4'],
            ['pages.show', 'privacy', 'monthly', '0.4'],
            ['pages.show', 'contacts', 'monthly', '0.5'],
        ];
        $urls = collect($pages)->flatMap(function (array $page): array {
            [$route, $parameters, $changefreq, $priority] = $page;
            $alternates = [
                'uk' => Locale::route($route, $parameters, locale: 'uk'),
                'ru' => Locale::route($route, $parameters, locale: 'ru'),
            ];

            return collect(Locale::SUPPORTED)->map(fn (string $locale): array => [
                $alternates[$locale], now(), $changefreq, $priority, $alternates,
            ])->all();
        })->all();

        return $this->urlset($urls);
    }

    public function blog(): Response
    {
        $urls = BlogPost::query()
            ->published()
            ->orderByDesc('published_at')
            ->get(['slug', 'updated_at', 'published_at'])
            ->flatMap(function (BlogPost $post): array {
                $alternates = [
                    'uk' => $post->url(locale: 'uk'),
                    'ru' => $post->url(locale: 'ru'),
                ];

                return collect(Locale::SUPPORTED)->map(fn (string $locale): array => [
                    $alternates[$locale], $post->updated_at ?? $post->published_at, 'monthly', '0.6', $alternates,
                ])->all();
            })
            ->all();

        return $this->urlset($urls);
    }

    public function categories(): Response
    {
        $urls = Category::query()
            ->where('is_active', true)
            ->where('name', '!=', 'ІБІС Зброя')
            ->orderBy('id')
            ->get()
            ->flatMap(function (Category $category): array {
                $alternates = [
                    'uk' => $category->catalogUrl(locale: 'uk'),
                    'ru' => $category->catalogUrl(locale: 'ru'),
                ];

                return collect(Locale::SUPPORTED)->map(fn (string $locale): array => [
                    $alternates[$locale], $category->updated_at, 'daily', '0.8', $alternates,
                ])->all();
            })
            ->all();

        return $this->urlset($urls);
    }

    public function products(int $page): StreamedResponse
    {
        abort_if($page < 1, 404);

        $chunk = $this->productChunk();
        $baseQuery = $this->indexableProducts()
            ->whereNotNull('category_id')
            ->where('slug', '!=', '');

        abort_if(! (clone $baseQuery)->forPage($page, $chunk)->exists(), 404);

        $ids = (clone $baseQuery)
            ->forPage($page, $chunk)
            ->orderBy('id')
            ->pluck('id');

        return response()->stream(function () use ($ids): void {
            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";

            foreach ($ids->chunk(200) as $idChunk) {
                Product::query()
                    ->whereIn('id', $idChunk)
                    ->with('category.parentRecursive')
                    ->orderBy('id')
                    ->get(['id', 'category_id', 'slug', 'updated_at', 'image_path', 'image_url'])
                    ->each(function (Product $product): void {
                        foreach ($this->productUrlEntries($product) as $entry) {
                            echo '  '.$this->formatUrlEntry($entry)."\n";
                        }
                    });
            }

            echo "</urlset>\n";
        }, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * @return list<array{0: string, 1: mixed, 2: string, 3: string, 4: array<string, string>, 5: string|null}>
     */
    private function productUrlEntries(Product $product): array
    {
        if (blank($product->slug)) {
            return [];
        }

        try {
            $alternates = [
                'uk' => $product->url(locale: 'uk'),
                'ru' => $product->url(locale: 'ru'),
            ];
            $image = $product->imageUrl();

            if (str_ends_with($image, '/images/product-placeholder.svg')) {
                $image = null;
            }
        } catch (\Throwable) {
            return [];
        }

        return collect(Locale::SUPPORTED)
            ->map(fn (string $locale): array => [
                $alternates[$locale], $product->updated_at, 'weekly', '0.7', $alternates, $image,
            ])
            ->all();
    }

    /**
     * @param  array{0: string, 1: mixed, 2: string, 3: string, 4?: array<string, string>, 5?: string|null}  $url
     */
    private function formatUrlEntry(array $url): string
    {
        [$loc, $lastmod, $changefreq, $priority] = $url;
        $alternates = $url[4] ?? [];
        $image = $url[5] ?? null;
        $links = collect($alternates)->map(
            fn (string $href, string $locale): string => '<xhtml:link rel="alternate" hreflang="'.$locale.'" href="'.$this->escape($href).'"/>'
        )->push('<xhtml:link rel="alternate" hreflang="x-default" href="'.$this->escape($alternates['uk'] ?? $loc).'"/>')->implode('');
        $imageXml = filled($image)
            ? '<image:image><image:loc>'.$this->escape($image).'</image:loc></image:image>'
            : '';

        return '<url><loc>'.$this->escape($loc).'</loc>'.$links.$imageXml.'<lastmod>'.$this->formatLastmod($lastmod).'</lastmod><changefreq>'.$changefreq.'</changefreq><priority>'.$priority.'</priority></url>';
    }

    private function urlset(array $urls): Response
    {
        return $this->xml(
            '<?xml version="1.0" encoding="UTF-8"?>'."\n".
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n".
            collect($urls)->map(fn (array $url): string => '  '.$this->formatUrlEntry($url))->implode("\n").
            "\n</urlset>"
        );
    }

    private function xml(string $content): Response
    {
        return response($content, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function formatLastmod(mixed $lastmod): string
    {
        if ($lastmod instanceof \DateTimeInterface) {
            return $lastmod->format('Y-m-d');
        }

        if (is_string($lastmod) && $lastmod !== '') {
            return substr($lastmod, 0, 10);
        }

        return now()->toDateString();
    }

    private function productChunk(): int
    {
        return max(500, (int) config('seo.sitemap_product_chunk', 2500));
    }

    private function indexableProducts()
    {
        return Product::query()
            ->where('is_active', true)
            ->where('is_indexable', true)
            ->where('canonical_type', 'self');
    }

    private function productionRobots(): string
    {
        $lines = [
            'User-agent: GPTBot',
            'Disallow: /',
            '',
            'User-agent: *',
            'Allow: /',
            '',
            '# Back office and framework endpoints',
            'Disallow: /admin/',
            'Disallow: /livewire/',
            '',
            '# Internal JSON and tracking endpoints',
            'Disallow: /catalog-menu',
            'Disallow: /filter-click',
            'Disallow: /search/suggestions',
            'Disallow: /search/click',
            'Disallow: /ru/catalog-menu',
            'Disallow: /ru/filter-click',
            'Disallow: /ru/search/suggestions',
            'Disallow: /ru/search/click',
            '',
            '# Prevent crawl traps from sorting and internal AJAX parameters',
            'Disallow: /*?*sort=',
            'Disallow: /*?*per_page=',
            'Disallow: /*?*search=',
            '',
            '# Prevent indexing crawl traps from faceted filter GET parameters',
            'Disallow: /*?*filter=',
            'Disallow: /*?*brand%5B',
            'Disallow: /*?*model%5B',
            'Disallow: /*?*season%5B',
            'Disallow: /*?*usage_type%5B',
            'Disallow: /*?*material%5B',
            'Disallow: /*?*spec%5B',
            'Disallow: /*?*min_price=',
            'Disallow: /*?*max_price=',
            'Disallow: /*?*max_weight=',
            'Disallow: /*?*in_stock=',
            'Disallow: /*?*on_sale=',
            'Disallow: /*?*fast_filters=',
            'Disallow: /*?*filters_only=',
            '',
            'Sitemap: '.route('sitemap.index'),
            '',
        ];

        return implode("\n", $lines);
    }
}
