<?php

namespace App\Filament\RichEditor\Blocks;

use App\Support\BlogHtmlSanitizer;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Str;

class BlogHtmlEmbedBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'blogHtmlEmbed';
    }

    public static function getLabel(): string
    {
        return 'HTML-блок';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('HTML-блок')
            ->modalDescription('Вставте HTML-розмітку. Вона буде відображена на сторінці статті (не як текст). Для YouTube/Vimeo зручніше використати блок «Відео».')
            ->schema([
                Textarea::make('html')
                    ->label('HTML')
                    ->rows(12)
                    ->required(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function getPreviewLabel(array $config): string
    {
        $snippet = Str::limit(trim(strip_tags((string) ($config['html'] ?? ''))), 48);

        return filled($snippet) ? "HTML: {$snippet}" : 'HTML-блок';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        $html = self::renderHtml($config);

        if (blank($html)) {
            return '<div class="blog-html-embed blog-html-embed--preview"><em>HTML-блок (порожній)</em></div>';
        }

        return '<div class="blog-html-embed blog-html-embed--preview">'.$html.'</div>';
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): string
    {
        $html = self::renderHtml($config);

        if (blank($html)) {
            return '';
        }

        return '<div class="blog-html-embed">'.$html.'</div>';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function renderHtml(array $config): string
    {
        $html = trim((string) ($config['html'] ?? ''));

        if ($html === '') {
            return '';
        }

        return BlogHtmlSanitizer::sanitize($html);
    }
}
