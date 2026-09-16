<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use App\Services\CatalogSpecificationFacets;
use App\Support\CategoryTreeBuilder;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Основне')
                    ->description('Для нової категорії достатньо назви. Решта полів — за бажанням.')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->schema([
                        CategoryResource::parentCategorySelect(),
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label('Назва (UK)')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(1),
                            TextInput::make('name_ru')
                                ->label('Название (RU)')
                                ->maxLength(255)
                                ->placeholder('Необов\'язково')
                                ->helperText('Якщо порожньо, на російській версії сайту показується українська назва.')
                                ->columnSpan(1),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('slug')
                                ->label('Slug (URL)')
                                ->maxLength(255)
                                ->unique(Category::class, 'slug', ignoreRecord: true)
                                ->helperText(fn (?Category $record): string => $record
                                    ? 'Обережно зі зміною — старі посилання перестануть працювати.'
                                    : 'Залиште порожнім — згенерується автоматично з назви.')
                                ->required(fn (?Category $record): bool => $record !== null)
                                ->columnSpan(1),
                            TextInput::make('sort_order')
                                ->label('Порядок сортування')
                                ->numeric()
                                ->default(0)
                                ->helperText('Менше число — вище в списку. При створенні ставиться автоматично.')
                                ->visible(fn (?Category $record): bool => $record !== null)
                                ->columnSpan(1),
                        ]),
                        Toggle::make('is_active')
                            ->label('Активна')
                            ->default(true)
                            ->inline(false),
                    ]),

                Section::make('Імпорт з фіду')
                    ->description('Для категорій, що приходять від контрагента.')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->collapsed()
                    ->visible(fn (?Category $record): bool => $record?->isFeedCategory() ?? false)
                    ->schema([
                        Select::make('target_category_id')
                            ->label('Категорія в каталозі')
                            ->helperText('Усі товари з цієї категорії фіду при імпорті потраплятимуть сюди. Окремо призначені товари зберігають свою категорію.')
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->options(fn (): array => app(CategoryTreeBuilder::class)->options()),
                    ]),

                Section::make('Зображення')
                    ->description('Необов\'язково. Без фото на сайті показується заглушка.')
                    ->icon(Heroicon::OutlinedPhoto)
                    ->collapsed(fn (?Category $record): bool => $record === null)
                    ->schema([
                        FileUpload::make('image_path')
                            ->label('Фото категорії')
                            ->helperText('Горизонтальне фото від 1200 × 900 px. Можна обрізати перед збереженням.')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios(['4:3', '1:1'])
                            ->disk('public')
                            ->directory('categories')
                            ->visibility('public')
                            ->maxSize(5120)
                            ->columnSpanFull(),
                        TextInput::make('image_url')
                            ->label('Зовнішній URL зображення')
                            ->helperText('Лише для імпортованих категорій. Завантажене фото має пріоритет.')
                            ->url()
                            ->visible(fn (?Category $record): bool => $record !== null)
                            ->columnSpanFull(),
                    ]),

                Section::make('Опис')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->collapsed()
                    ->schema([
                        Textarea::make('description')
                            ->label('Опис категорії (UK)')
                            ->rows(4)
                            ->columnSpanFull(),
                        Textarea::make('description_ru')
                            ->label('Описание категории (RU)')
                            ->rows(4)
                            ->columnSpanFull(),
                        Textarea::make('seo_intro')
                            ->label('Короткий вступ (UK)')
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('seo_intro_ru')
                            ->label('Короткое вступление (RU)')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('SEO')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->collapsed()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('h1')
                                ->label('H1 (UK)'),
                            TextInput::make('h1_ru')
                                ->label('H1 (RU)'),
                        ]),
                        TextInput::make('seo_title')
                            ->label('SEO title (UK)')
                            ->columnSpanFull(),
                        TextInput::make('seo_title_ru')
                            ->label('SEO title (RU)')
                            ->columnSpanFull(),
                        Textarea::make('meta_description')
                            ->label('Meta description (UK)')
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('meta_description_ru')
                            ->label('Meta description (RU)')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('Фільтри каталогу')
                    ->description('Налаштування блоків фільтрів у каталозі цієї категорії.')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->collapsed()
                    ->schema([
                        CheckboxList::make('visible_filters')
                            ->label('Основні фільтри')
                            ->helperText('За замовчуванням — бренд, модель і ціна. Інші фільтри показуються лише якщо увімкнені тут і є дані в товарах.')
                            ->options([
                                'sale' => 'Акційні товари',
                                'brand' => 'Бренд',
                                'model' => 'Модель',
                                'price' => 'Ціна',
                                'season' => 'Сезон',
                                'usage_type' => 'Тип використання',
                                'material' => 'Матеріал',
                                'weight' => 'Максимальна вага',
                                'stock' => 'Тільки в наявності',
                            ])
                            ->default(['brand', 'model', 'price'])
                            ->bulkToggleable()
                            ->columns(2)
                            ->columnSpanFull(),
                        CheckboxList::make('visible_spec_filters')
                            ->label('Фільтри з характеристик товарів')
                            ->helperText('Доступні після збереження категорії, коли в ній з\'являться товари.')
                            ->options(function (?Category $record): array {
                                if (! $record) {
                                    return [];
                                }

                                $options = app(CatalogSpecificationFacets::class)->availableFilterOptions($record, includeInactive: true);

                                foreach ((array) $record->visible_spec_filters as $key) {
                                    if (app(CatalogSpecificationFacets::class)->isFilterableKey((string) $key)) {
                                        $options[$key] ??= $key.' (збережений фільтр)';
                                    }
                                }

                                return $options;
                            })
                            ->searchable()
                            ->bulkToggleable()
                            ->columns(2)
                            ->visible(fn (?Category $record): bool => $record !== null)
                            ->columnSpanFull(),
                        TagsInput::make('visible_spec_filters_ru')
                            ->label('Ключі характеристик для фільтрів (RU)')
                            ->helperText('Заповнюйте, якщо російські назви характеристик відрізняються.')
                            ->visible(fn (?Category $record): bool => $record !== null)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
