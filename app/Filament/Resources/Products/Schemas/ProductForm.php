<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Основне')
                    ->description('Для нового товару достатньо категорії, назви, ціни та залишку.')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->schema([
                        ProductResource::categorySelect(),
                        Grid::make(2)->schema([
                            ProductResource::brandField(),
                            ProductResource::modelField(),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label('Назва (UK)')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('name_ru')
                                ->label('Название (RU)')
                                ->maxLength(255)
                                ->placeholder('Необов\'язково'),
                        ]),
                        TextInput::make('slug')
                            ->label('Slug (URL)')
                            ->maxLength(255)
                            ->unique(Product::class, 'slug', ignoreRecord: true)
                            ->helperText(fn (?Product $record): string => $record
                                ? 'Обережно зі зміною — старі посилання перестануть працювати.'
                                : 'Залиште порожнім — згенерується автоматично з назви.')
                            ->required(fn (?Product $record): bool => $record !== null),
                        Grid::make(3)->schema([
                            TextInput::make('price')
                                ->label('Ціна')
                                ->required()
                                ->numeric()
                                ->minValue(0)
                                ->suffix('₴'),
                            TextInput::make('discount_percent')
                                ->label('Знижка')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(90)
                                ->suffix('%')
                                ->default(0),
                            TextInput::make('stock')
                                ->label('Залишок')
                                ->required()
                                ->numeric()
                                ->minValue(0)
                                ->default(1),
                        ]),
                        Grid::make(3)->schema([
                            Toggle::make('is_active')
                                ->label('Активний')
                                ->default(true)
                                ->live()
                                ->afterStateUpdated(function (bool $state, callable $set): void {
                                    if ($state) {
                                        $set('is_processed', true);
                                    }
                                })
                                ->inline(false),
                            Toggle::make('is_processed')
                                ->label('Оброблений')
                                ->helperText('Оброблені товари з’являються у вкладці «Оброблені». Увімкнення «Активний» автоматично позначає товар обробленим.')
                                ->default(true)
                                ->inline(false),
                            Toggle::make('is_featured')
                                ->label('Популярний')
                                ->default(false)
                                ->inline(false),
                        ]),
                    ]),

                Section::make('Зображення')
                    ->description('Завантажте фото з комп’ютера або додайте зовнішні URL для імпортованих товарів.')
                    ->icon(Heroicon::OutlinedPhoto)
                    ->collapsed(fn (?Product $record): bool => $record === null)
                    ->schema([
                        FileUpload::make('image_path')
                            ->label('Головне фото')
                            ->helperText('Квадратне або горизонтальне фото. Можна обрізати перед збереженням.')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios(['1:1', '4:3'])
                            ->disk('public')
                            ->directory('products')
                            ->visibility('public')
                            ->maxSize(5120)
                            ->columnSpanFull(),
                        FileUpload::make('gallery_paths')
                            ->label('Галерея')
                            ->helperText('Додаткові фото товару. Перетягніть для зміни порядку.')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->appendFiles()
                            ->disk('public')
                            ->directory('products/gallery')
                            ->visibility('public')
                            ->maxSize(5120)
                            ->maxFiles(12)
                            ->columnSpanFull(),
                        FileUpload::make('content_image_paths')
                            ->label('Фото в контентному блоці')
                            ->helperText('Зображення для унікального текстового блоку на сторінці товару.')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->appendFiles()
                            ->disk('public')
                            ->directory('products/content')
                            ->visibility('public')
                            ->maxSize(5120)
                            ->maxFiles(6)
                            ->columnSpanFull(),
                        TextInput::make('image_url')
                            ->label('Або головне фото (URL)')
                            ->helperText('Якщо завантажили файл вище — URL не обов’язковий.')
                            ->url()
                            ->columnSpanFull(),
                        TagsInput::make('gallery_images')
                            ->label('Або галерея (URL)')
                            ->helperText('Для імпортованих товарів. Завантажені фото мають пріоритет.')
                            ->columnSpanFull(),
                        TagsInput::make('content_images')
                            ->label('Або контент (URL)')
                            ->columnSpanFull(),
                    ]),

                Section::make('Опис')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->collapsed()
                    ->schema([
                        Textarea::make('description')
                            ->label('Короткий опис (UK)')
                            ->rows(4)
                            ->columnSpanFull(),
                        Textarea::make('description_ru')
                            ->label('Короткое описание (RU)')
                            ->rows(4)
                            ->columnSpanFull(),
                        Textarea::make('content')
                            ->label('Унікальний контент сторінки (UK)')
                            ->rows(6)
                            ->columnSpanFull(),
                        Textarea::make('content_ru')
                            ->label('Уникальный контент страницы (RU)')
                            ->rows(6)
                            ->columnSpanFull(),
                    ]),

                Section::make('Характеристики')
                    ->description('Використовуються у фільтрах каталогу та на сторінці товару.')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->collapsed()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('season')
                                ->label('Сезон (UK)'),
                            TextInput::make('season_ru')
                                ->label('Сезон (RU)'),
                            TextInput::make('usage_type')
                                ->label('Тип використання (UK)'),
                            TextInput::make('usage_type_ru')
                                ->label('Тип использования (RU)'),
                            TextInput::make('material')
                                ->label('Матеріал (UK)'),
                            TextInput::make('material_ru')
                                ->label('Материал (RU)'),
                            TextInput::make('weight_grams')
                                ->label('Вага')
                                ->numeric()
                                ->suffix('г'),
                        ]),
                        KeyValue::make('specifications')
                            ->label('Характеристики (UK)')
                            ->keyLabel('Назва')
                            ->valueLabel('Значення')
                            ->columnSpanFull(),
                        KeyValue::make('specifications_ru')
                            ->label('Характеристики (RU)')
                            ->keyLabel('Название')
                            ->valueLabel('Значение')
                            ->columnSpanFull(),
                    ]),

                Section::make('Варіанти')
                    ->description('Для товарів з кольорами, розмірами або іншими модифікаціями.')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->collapsed()
                    ->schema([
                        Select::make('variant_group_id')
                            ->label('Варіантна група')
                            ->relationship('variantGroup', 'title')
                            ->searchable()
                            ->preload(),
                        Toggle::make('is_primary_variant')
                            ->label('Головний товар у групі')
                            ->inline(false),
                        KeyValue::make('variant_options')
                            ->label('Опції варіанта (UK)')
                            ->keyLabel('Назва')
                            ->valueLabel('Значення')
                            ->columnSpanFull(),
                        KeyValue::make('variant_options_ru')
                            ->label('Опции варианта (RU)')
                            ->keyLabel('Название')
                            ->valueLabel('Значение')
                            ->columnSpanFull(),
                        KeyValue::make('variant_secondary_specs')
                            ->label('Другорядні характеристики варіанта (UK)')
                            ->keyLabel('Назва')
                            ->valueLabel('Значення')
                            ->columnSpanFull(),
                        KeyValue::make('variant_secondary_specs_ru')
                            ->label('Второстепенные характеристики варианта (RU)')
                            ->keyLabel('Название')
                            ->valueLabel('Значение')
                            ->columnSpanFull(),
                    ]),

                Section::make('SEO')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->collapsed()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('h1')
                                ->label('H1 (UK)')
                                ->maxLength(255),
                            TextInput::make('h1_ru')
                                ->label('H1 (RU)')
                                ->maxLength(255),
                        ]),
                        TextInput::make('seo_title')
                            ->label('SEO title (UK)')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('seo_title_ru')
                            ->label('SEO title (RU)')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('meta_description')
                            ->label('Meta description (UK)')
                            ->maxLength(500)
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('meta_description_ru')
                            ->label('Meta description (RU)')
                            ->maxLength(500)
                            ->rows(3)
                            ->columnSpanFull(),
                        Toggle::make('is_indexable')
                            ->label('Індексувати сторінку')
                            ->default(true)
                            ->inline(false),
                        Grid::make(2)->schema([
                            Select::make('canonical_type')
                                ->label('Canonical')
                                ->options([
                                    'self' => 'На себе',
                                    'primary' => 'На головний варіант',
                                    'custom' => 'На інший товар',
                                ])
                                ->default('self')
                                ->required()
                                ->native(false),
                            Select::make('canonical_product_id')
                                ->label('Canonical товар')
                                ->relationship('canonicalProduct', 'name')
                                ->searchable()
                                ->preload(),
                        ]),
                    ]),

                Section::make('Службові поля')
                    ->description('Коди та дані імпорту. Зазвичай не потрібно змінювати вручну.')
                    ->icon(Heroicon::OutlinedIdentification)
                    ->collapsed()
                    ->visible(fn (?Product $record): bool => $record !== null)
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('sku')
                                ->label('Код товару Kubii')
                                ->readOnly()
                                ->helperText('Генерується автоматично після збереження.'),
                            Placeholder::make('counterparty_label')
                                ->label('Контрагент')
                                ->content(fn (?Product $record): string => $record?->counterparty?->name ?? '—')
                                ->visible(fn (?Product $record): bool => filled($record?->counterparty_id)),
                            TextInput::make('external_id')
                                ->label('Код постачальника')
                                ->disabled()
                                ->dehydrated(false)
                                ->visible(fn (?Product $record): bool => filled($record?->external_id)),
                            TextInput::make('variant_id')
                                ->label('Variant ID')
                                ->maxLength(255)
                                ->visible(fn (?Product $record): bool => filled($record?->variant_id)),
                        ]),
                    ]),
            ]);
    }
}
