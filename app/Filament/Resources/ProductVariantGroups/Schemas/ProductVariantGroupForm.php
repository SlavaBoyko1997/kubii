<?php

namespace App\Filament\Resources\ProductVariantGroups\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductVariantGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label('Назва групи')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Select::make('status')
                    ->label('Статус')
                    ->options([
                        'auto_suggested' => 'Авто-пропозиція',
                        'needs_review' => 'Потрібна перевірка',
                        'approved' => 'Підтверджено',
                        'rejected' => 'Відхилено',
                        'published' => 'Опубліковано',
                    ])
                    ->required(),
                Select::make('category_id')
                    ->label('Категорія')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->required(),
                Select::make('primary_product_id')
                    ->label('Головний товар')
                    ->relationship('primaryProduct', 'name')
                    ->searchable(),
                TextInput::make('brand')
                    ->label('Бренд')
                    ->maxLength(255),
                TextInput::make('grouping_level')
                    ->label('Рівень групування')
                    ->maxLength(255),
                TextInput::make('confidence')
                    ->label('Впевненість')
                    ->numeric()
                    ->suffix('%'),
                TagsInput::make('variant_option_keys')
                    ->label('Ключі опцій варіанта')
                    ->helperText('Наприклад: Колір, Розмір, Довжина, Тест.')
                    ->columnSpanFull(),
                TagsInput::make('secondary_spec_keys')
                    ->label('Другорядні характеристики')
                    ->columnSpanFull(),
                Textarea::make('candidate_summary')
                    ->label('Підсумок авто-групування')
                    ->formatStateUsing(fn ($state): string => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '')
                    ->dehydrated(false)
                    ->columnSpanFull(),
                Textarea::make('admin_notes')
                    ->label('Нотатки адміністратора')
                    ->columnSpanFull(),
            ]);
    }
}
