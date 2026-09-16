<?php

namespace App\Filament\Resources\Reviews\Schemas;

use App\Models\Review;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ReviewForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('product.name')->label('Товар')->disabled(),
                TextInput::make('user.name')->label('Клієнт')->disabled(),
                TextInput::make('parent.user.name')->label('Відповідь на відгук клієнта')->disabled(),
                Select::make('rating')->label('Оцінка')->options([
                    1 => '1 зірка',
                    2 => '2 зірки',
                    3 => '3 зірки',
                    4 => '4 зірки',
                    5 => '5 зірок',
                ])->required(fn (?Review $record): bool => ! $record?->parent_id),
                Toggle::make('is_visible')->label('Опублікований')->required(),
                Toggle::make('is_verified_purchase')->label('Підтверджена покупка'),
                Toggle::make('is_demo')->label('Тестовий відгук')->disabled(),
                Toggle::make('is_ai_generated')->label('AI-згенерований')->disabled(),
                TextInput::make('title')->label('Заголовок'),
                Textarea::make('body')->label('Текст відгуку')->required()->columnSpanFull(),
                Textarea::make('pros')->label('Переваги')->columnSpanFull(),
                Textarea::make('cons')->label('Недоліки')->columnSpanFull(),
            ]);
    }
}
