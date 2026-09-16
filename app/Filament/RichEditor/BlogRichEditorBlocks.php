<?php

namespace App\Filament\RichEditor;

use App\Filament\RichEditor\Blocks\BlogHtmlEmbedBlock;
use App\Filament\RichEditor\Blocks\BlogVideoEmbedBlock;

class BlogRichEditorBlocks
{
    /**
     * @return array<class-string>
     */
    public static function classes(): array
    {
        return [
            BlogHtmlEmbedBlock::class,
            BlogVideoEmbedBlock::class,
        ];
    }

    /**
     * @return array<class-string>
     */
    public static function forEditor(): array
    {
        return self::classes();
    }
}
