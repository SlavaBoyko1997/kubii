<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Support\Locale;
use Illuminate\Support\Str;

class SeoMeta
{
    private const IMAGE_WIDTH = 1200;

    private const IMAGE_HEIGHT = 630;

    private const SOCIAL_IMAGE_VERSION = 2;

    public function home(): array
    {
        return $this->make(
            title: 'Kubii',
            description: __('Kubii — спорядження для туризму, кемпінгу та риболовлі з доставкою по Україні.'),
            url: localized_route('home'),
            image: $this->defaultImage(),
            imageAlt: __('Kubii — спорядження для туризму та риболовлі'),
        );
    }

    public function category(Category $category, string $url, string $filterSuffix = '', int $page = 1): array
    {
        $pageSuffix = $this->pageSuffix($page);

        return $this->make(
            title: $category->filteredSeoTitle($filterSuffix).$pageSuffix,
            description: $category->filteredMetaDescription($filterSuffix).$pageSuffix,
            url: $url,
            image: $this->modelImage('social-images.category', $category),
            imageAlt: $category->name,
        );
    }

    public function product(Product $product): array
    {
        $extra = [
            'product:price:amount' => $product->isPricePending()
                ? null
                : number_format($product->salePrice(), 2, '.', ''),
            'product:price:currency' => $product->isPricePending() ? null : 'UAH',
            'product:availability' => $product->isPurchasable() ? 'in stock' : 'out of stock',
        ];

        return $this->make(
            title: $product->seoTitle(),
            description: $product->metaDescription(),
            url: $product->canonicalUrl(),
            type: 'product',
            image: $this->modelImage('social-images.product', $product),
            imageAlt: $product->name,
            extra: $extra,
        );
    }

    public function search(string $query, string $url): array
    {
        $title = $query !== ''
            ? __('Результати для «:query»', ['query' => $query])
            : __('Пошук товарів');
        $description = $query !== ''
            ? __('Результати пошуку товарів за запитом «:query».', ['query' => $query])
            : __('Пошук товарів у каталозі Kubii.');

        return $this->make($title, $description, $url);
    }

    public function page(string $title, string $description, string $url): array
    {
        return $this->make($title, $description, $url);
    }

    public function article(
        string $title,
        string $description,
        string $url,
        ?string $image = null,
        ?string $publishedAt = null,
        ?string $modifiedAt = null,
        ?string $author = null,
    ): array {
        return $this->make(
            title: $title,
            description: $description,
            url: $url,
            type: 'article',
            image: $image ? $this->absoluteSecureUrl($image) : $this->defaultImage(),
            imageAlt: $title,
            extra: [
                'article:published_time' => $publishedAt,
                'article:modified_time' => $modifiedAt,
                'article:author' => $author,
            ],
        );
    }

    public function generic(?string $title = null, ?string $description = null, ?string $url = null): array
    {
        return $this->make(
            title: $title ?: 'Kubii',
            description: $description ?: __('Kubii — спорядження для туризму, кемпінгу та риболовлі з доставкою по Україні.'),
            url: $url ?: request()->url(),
        );
    }

    private function make(
        string $title,
        string $description,
        string $url,
        string $type = 'website',
        ?string $image = null,
        ?string $imageAlt = null,
        array $extra = [],
    ): array {
        $title = $this->plainText($title) ?: 'Kubii';
        $description = Str::limit(
            $this->plainText($description) ?: $title.' — Kubii.',
            180,
            '…',
        );
        $image = $image ?: $this->defaultImage();

        return [
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'image_secure_url' => $this->absoluteSecureUrl($image),
            'image_width' => self::IMAGE_WIDTH,
            'image_height' => self::IMAGE_HEIGHT,
            'image_alt' => $this->plainText($imageAlt ?: $title),
            'url' => $this->absoluteUrl($url),
            'type' => $type,
            'site_name' => 'Kubii',
            'locale' => Locale::isRussian() ? 'ru_RU' : 'uk_UA',
            'locale_alternates' => [Locale::isRussian() ? 'uk_UA' : 'ru_RU'],
            'twitter_card' => 'summary_large_image',
            'extra' => collect($extra)->filter(fn ($value): bool => filled($value))->all(),
        ];
    }

    private function modelImage(string $routeName, Product|Category $model): string
    {
        $version = ((int) ($model->updated_at?->timestamp ?? 0) * 10) + self::SOCIAL_IMAGE_VERSION;

        $path = route($routeName, [
            $model,
            'version' => $version,
        ], false);

        return secure_url($path);
    }

    private function defaultImage(): string
    {
        return secure_asset('images/social-default.jpg');
    }

    private function plainText(mixed $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $value))) ?? '');
    }

    private function pageSuffix(int $page): string
    {
        if ($page <= 1) {
            return '';
        }

        return Locale::isRussian()
            ? " — Страница {$page}"
            : " — Сторінка {$page}";
    }

    private function absoluteUrl(string $value): string
    {
        return preg_match('#^https?://#i', $value) === 1
            ? $value
            : url('/'.ltrim($value, '/'));
    }

    private function absoluteSecureUrl(string $value): string
    {
        $url = $this->absoluteUrl($value);

        return preg_replace('#^http://#i', 'https://', $url) ?: $url;
    }
}
