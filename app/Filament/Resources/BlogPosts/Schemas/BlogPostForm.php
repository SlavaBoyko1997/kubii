<?php

namespace App\Filament\Resources\BlogPosts\Schemas;

use App\Filament\RichEditor\BlogRichEditorBlocks;
use App\Models\BlogPost;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class BlogPostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Основне')
                ->icon(Heroicon::OutlinedDocumentText)
                ->schema([
                    TextInput::make('title')
                        ->label('Назва статті')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, callable $set, ?BlogPost $record): void {
                            if ($record !== null || blank($state)) {
                                return;
                            }

                            $set('slug', BlogPost::uniqueSlug($state));
                        })
                        ->columnSpanFull(),
                    TextInput::make('slug')
                        ->label('Slug (URL)')
                        ->required()
                        ->maxLength(255)
                        ->unique(BlogPost::class, 'slug', ignoreRecord: true)
                        ->helperText('Відображається в адресі: /blog/slug-statti')
                        ->columnSpanFull(),
                    Textarea::make('short_description')
                        ->label('Короткий опис')
                        ->helperText('Для картки блогу та meta description, якщо SEO-опис порожній.')
                        ->rows(3)
                        ->maxLength(500)
                        ->columnSpanFull(),
                    Grid::make(2)->schema([
                        Select::make('status')
                            ->label('Статус')
                            ->options([
                                BlogPost::STATUS_DRAFT => 'Чернетка',
                                BlogPost::STATUS_PUBLISHED => 'Опубліковано',
                            ])
                            ->default(BlogPost::STATUS_DRAFT)
                            ->required()
                            ->native(false)
                            ->live(),
                        DateTimePicker::make('published_at')
                            ->label('Дата публікації')
                            ->seconds(false)
                            ->helperText('Стаття з’явиться на сайті лише після цієї дати.'),
                    ]),
                    Grid::make(2)->schema([
                        Toggle::make('is_featured')
                            ->label('Виділити статтю')
                            ->helperText('Показувати вище інших у списку блогу.')
                            ->default(false),
                        TextInput::make('sort_order')
                            ->label('Порядок сортування')
                            ->numeric()
                            ->default(0)
                            ->required(),
                    ]),
                ]),

            Section::make('Зображення')
                ->description('Рекомендований розмір банера: 1600 × 640 px (формат 2:1).')
                ->icon(Heroicon::OutlinedPhoto)
                ->schema([
                    FileUpload::make('banner_image')
                        ->label('Банер статті')
                        ->image()
                        ->imageEditor()
                        ->imageEditorAspectRatios(['16:9', '2:1'])
                        ->disk('public')
                        ->directory('blog/banners')
                        ->visibility('public')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(8192)
                        ->columnSpanFull(),
                    FileUpload::make('preview_image')
                        ->label('Фото для картки')
                        ->helperText('Необов’язково. Якщо порожньо — використовується банер.')
                        ->image()
                        ->disk('public')
                        ->directory('blog/previews')
                        ->visibility('public')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(4096)
                        ->columnSpanFull(),
                ]),

            Section::make('Текст статті')
                ->description('Для HTML або відео натисніть «+» у редакторі та оберіть блок «HTML-блок» або «Відео». Кнопка «Код» показує текст як є — лише для прикладів коду.')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->schema([
                    RichEditor::make('content')
                        ->label('Контент')
                        ->required()
                        ->customBlocks(BlogRichEditorBlocks::forEditor())
                        ->fileAttachmentsDisk('public')
                        ->fileAttachmentsDirectory('blog/content')
                        ->fileAttachmentsVisibility('public')
                        ->fileAttachmentsAcceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                        ->fileAttachmentsMaxSize(8192)
                        ->toolbarButtons([
                            ['bold', 'italic', 'underline', 'link'],
                            ['h2', 'h3'],
                            ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
                            ['table', 'attachFiles'],
                            ['undo', 'redo'],
                        ])
                        ->columnSpanFull(),
                ]),

            Section::make('CSS для статті')
                ->description('Додаткові стилі застосовуються лише на сторінці цієї статті.')
                ->icon(Heroicon::OutlinedCodeBracket)
                ->collapsed()
                ->schema([
                    CodeEditor::make('custom_css')
                        ->label('Custom CSS')
                        ->language(Language::Css)
                        ->columnSpanFull(),
                ]),

            Section::make('SEO')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->collapsed()
                ->schema([
                    TextInput::make('seo_title')
                        ->label('SEO title')
                        ->maxLength(255)
                        ->helperText('Якщо порожньо — використовується назва статті.')
                        ->columnSpanFull(),
                    Textarea::make('seo_description')
                        ->label('SEO description')
                        ->rows(3)
                        ->maxLength(320)
                        ->helperText('Якщо порожньо — короткий опис або початок контенту.')
                        ->columnSpanFull(),
                    TextInput::make('seo_keywords')
                        ->label('SEO keywords')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('canonical_url')
                        ->label('Canonical URL')
                        ->url()
                        ->maxLength(2048)
                        ->helperText('Залиште порожнім для стандартної адреси /blog/slug.')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
