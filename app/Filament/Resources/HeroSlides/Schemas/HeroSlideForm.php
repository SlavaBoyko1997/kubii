<?php

namespace App\Filament\Resources\HeroSlides\Schemas;

use App\Models\HeroSlide;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class HeroSlideForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Текст банера')
                ->icon(Heroicon::OutlinedDocumentText)
                ->schema([
                    TextInput::make('title')
                        ->label('Заголовок (H1)')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('subtitle')
                        ->label('Підзаголовок')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('button_label')
                        ->label('Текст кнопки')
                        ->helperText('Залиште порожнім, якщо кнопка не потрібна.')
                        ->maxLength(100)
                        ->placeholder('До каталогу'),
                    TextInput::make('button_url')
                        ->label('Посилання кнопки або банера')
                        ->helperText('Якщо текст кнопки порожній, посилання відкриватиметься при натисканні на весь банер.')
                        ->url()
                        ->maxLength(255),
                ]),

            Section::make('Зображення')
                ->description('Горизонтальне фото від 1600 × 640 px.')
                ->icon(Heroicon::OutlinedPhoto)
                ->schema([
                    FileUpload::make('image_path')
                        ->label('Фото банера')
                        ->image()
                        ->imageEditor()
                        ->imageEditorAspectRatios(['16:9', '2:1'])
                        ->disk('public')
                        ->directory('hero-slides')
                        ->visibility('public')
                        ->maxSize(8192)
                        ->columnSpanFull(),
                    TextInput::make('image_url')
                        ->label('Зовнішній URL зображення')
                        ->helperText('Використовується, якщо фото не завантажене вище.')
                        ->url()
                        ->visible(fn (?HeroSlide $record): bool => $record !== null)
                        ->columnSpanFull(),
                ]),

            Section::make('Публікація')
                ->icon(Heroicon::OutlinedEye)
                ->schema([
                    Toggle::make('is_active')
                        ->label('Активний')
                        ->helperText('Вимкнені банери не показуються на сайті.')
                        ->default(true),
                    TextInput::make('sort_order')
                        ->label('Порядок показу')
                        ->numeric()
                        ->default(0)
                        ->required(),
                ]),
        ]);
    }
}
