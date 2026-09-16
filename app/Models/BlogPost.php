<?php

namespace App\Models;

use App\Filament\RichEditor\BlogRichEditorBlocks;
use App\Support\BlogHtmlSanitizer;
use App\Support\StoredAsset;
use Database\Factories\BlogPostFactory;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BlogPost extends Model
{
    /** @use HasFactory<BlogPostFactory> */
    use HasFactory;

    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'title',
        'slug',
        'banner_image',
        'preview_image',
        'short_description',
        'content',
        'custom_css',
        'seo_title',
        'seo_description',
        'seo_keywords',
        'canonical_url',
        'status',
        'is_featured',
        'sort_order',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('published_at');
    }

    public function isPublic(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->lte(now());
    }

    public function seoTitle(): string
    {
        return filled($this->seo_title) ? $this->seo_title : $this->title;
    }

    public function seoDescription(): string
    {
        if (filled($this->seo_description)) {
            return $this->seo_description;
        }

        if (filled($this->short_description)) {
            return $this->short_description;
        }

        return Str::limit(strip_tags($this->renderedContent()), 160);
    }

    public function url(?string $locale = null): string
    {
        return localized_route('blog.show', $this->slug, locale: $locale);
    }

    public function canonicalUrl(): string
    {
        if (filled($this->canonical_url)) {
            return $this->canonical_url;
        }

        return $this->url();
    }

    public function bannerImageUrl(): ?string
    {
        return StoredAsset::url($this->banner_image);
    }

    public function previewImageUrl(): ?string
    {
        return StoredAsset::url($this->preview_image) ?: $this->bannerImageUrl();
    }

    public function cardImageUrl(): ?string
    {
        return $this->previewImageUrl() ?: asset('images/hero-outdoor.webp');
    }

    public function socialImageUrl(): string
    {
        return $this->bannerImageUrl() ?: $this->previewImageUrl() ?: secure_asset('images/social-default.jpg');
    }

    public function renderedContent(): string
    {
        if (blank($this->content)) {
            return '';
        }

        return BlogHtmlSanitizer::sanitize(
            RichContentRenderer::make($this->content)
                ->fileAttachmentsDisk('public')
                ->fileAttachmentsVisibility('public')
                ->customBlocks(BlogRichEditorBlocks::classes())
                ->toUnsafeHtml()
        );
    }

    public function safeCustomCss(): string
    {
        if (blank($this->custom_css)) {
            return '';
        }

        $css = preg_replace('/<\/?script\b[^>]*>/i', '', $this->custom_css) ?? '';

        return trim($css);
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'post';
        $slug = $base;
        $suffix = 2;

        while (static::withTrashed()
            ->when($ignoreId, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return static::query()
            ->published()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }
}
