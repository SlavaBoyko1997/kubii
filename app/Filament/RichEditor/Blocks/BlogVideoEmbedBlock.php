<?php

namespace App\Filament\RichEditor\Blocks;

use App\Support\BlogVideoEmbed;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\TextInput;

class BlogVideoEmbedBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'blogVideoEmbed';
    }

    public static function getLabel(): string
    {
        return 'Відео';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('Вставка відео')
            ->modalDescription('Посилання на YouTube або Vimeo. Відео відобразиться на сторінці статті.')
            ->schema([
                TextInput::make('url')
                    ->label('Посилання на відео')
                    ->url()
                    ->required()
                    ->placeholder('https://www.youtube.com/watch?v=...')
                    ->helperText('Підтримуються YouTube та Vimeo.'),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function getPreviewLabel(array $config): string
    {
        $url = trim((string) ($config['url'] ?? ''));

        return filled($url) ? "Відео: {$url}" : 'Відео';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        return self::toHtml($config, []) ?: '<div class="blog-video-embed blog-video-embed--preview"><em>Невалідне посилання на відео</em></div>';
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): string
    {
        return BlogVideoEmbed::embedHtml($config['url'] ?? null) ?? '';
    }
}
