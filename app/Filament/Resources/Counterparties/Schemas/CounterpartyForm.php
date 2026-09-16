<?php

namespace App\Filament\Resources\Counterparties\Schemas;

use App\Models\Counterparty;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class CounterpartyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Основне')
                    ->icon(Heroicon::OutlinedBuildingStorefront)
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label('Назва')
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (?string $state, callable $set, ?Counterparty $record): void {
                                    if ($record !== null || blank($state)) {
                                        return;
                                    }

                                    $set('slug', Counterparty::uniqueSlug($state));
                                }),
                            TextInput::make('slug')
                                ->label('Системний код')
                                ->required()
                                ->maxLength(255)
                                ->unique(Counterparty::class, 'slug', ignoreRecord: true)
                                ->helperText('Використовується як source товарів і для команди sync.'),
                        ]),
                        Toggle::make('is_active')
                            ->label('Активний')
                            ->default(true)
                            ->inline(false),
                        Textarea::make('notes')
                            ->label('Примітки')
                            ->columnSpanFull(),
                    ]),

                Section::make('Фід товарів')
                    ->description('Додайте URL фіду та натисніть «Обробити фід». YML і XML — один формат Yandex Market; Camotec віддає його як .xml. Створюється дерево категорій (вимкнене) і товари без публікації.')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->schema([
                        TextInput::make('feed_url')
                            ->label('URL фіду')
                            ->url()
                            ->maxLength(2048)
                            ->placeholder('https://atlantmarket.com.ua/price1/prom/atlantmarketprom(false).xml')
                            ->columnSpanFull(),
                        Grid::make(2)->schema([
                            Select::make('feed_format')
                                ->label('Формат файлу')
                                ->options([
                                    'yml' => 'YML (.yml)',
                                    'xml' => 'XML YML (.xml)',
                                    'atom' => 'Atom / Google Merchant',
                                ])
                                ->default('yml')
                                ->required()
                                ->native(false)
                                ->live()
                                ->helperText('Camotec / Gurt зазвичай публікує .xml — оберіть «XML YML». Всередині той самий yml_catalog.')
                                ->afterStateUpdated(function (?string $state, callable $set): void {
                                    if ($state === 'xml') {
                                        $set('feed_profile', 'camotec');
                                    }
                                }),
                            Select::make('feed_profile')
                                ->label('Профіль постачальника')
                                ->options([
                                    'standard' => 'Стандартний (Pobedov та подібні)',
                                    'camotec' => 'Camotec / Gurt',
                                    'atlantmarket' => 'Atlant Market / Prom / iidlo',
                                    'ranger' => 'Ranger',
                                    'trampopt' => 'Tramp Opt (ecomm.plus)',
                                    'travelextreme' => 'Travel Extreme',
                                    'voltmarket' => 'Voltmarket',
                                    'salmo' => 'Salmo',
                                    'ibis' => 'IBIS Gear',
                                ])
                                ->default('standard')
                                ->required()
                                ->native(false)
                                ->helperText(fn (callable $get): string => match ($get('feed_profile')) {
                                    'camotec' => 'Camotec: quantityStatus, groupId, sizeTitle, vendor як бренд.',
                                    'atlantmarket' => 'Atlant Market / Prom / iidlo: vendor як бренд, available для наявності, barcode або vendorCode як артикул.',
                                    'ranger' => 'Ranger: name_ua, description_ua, vendor, barcode, stock_quantity та available.',
                                    'trampopt' => 'Tramp Opt: quantity_in_stock, group_id, vendor як бренд, vendorCode як артикул.',
                                    'travelextreme' => 'Travel Extreme: український YML, stock_quantity як точний залишок, offer id як артикул.',
                                    'voltmarket' => 'Voltmarket: Google/Atom feed, імпорт тільки генераторів, ДБЖ, інверторів і зарядних пристроїв.',
                                    'salmo' => 'Salmo: vendorCode як артикул, vendor як бренд, available для наявності, name_ua/description_ua.',
                                    'ibis' => 'IBIS Gear: Google Merchant RSS item, g:id, g:brand, g:availability, g:prop, українські й російські поля.',
                                    default => 'Pobedov: name_ua, quantity_in_stock.',
                                }),
                            Toggle::make('auto_sync')
                                ->label('Автооновлення за розкладом')
                                ->helperText('Активні фіди запускаються о 04:00, 12:00 і 20:00 за Києвом.')
                                ->default(true)
                                ->inline(false),
                        ]),
                    ]),

                Section::make('Статус синхронізації')
                    ->icon(Heroicon::OutlinedClock)
                    ->visible(fn (?Counterparty $record): bool => $record !== null)
                    ->schema([
                        Grid::make(3)->schema([
                            Placeholder::make('last_synced_at_display')
                                ->label('Остання синхронізація')
                                ->content(fn (Counterparty $record): string => $record->last_synced_at?->format('d.m.Y H:i') ?? 'Ще не виконувалась'),
                            Placeholder::make('last_sync_products_count_display')
                                ->label('Товарів у фіді')
                                ->content(fn (Counterparty $record): string => $record->last_sync_products_count !== null
                                    ? number_format($record->last_sync_products_count)
                                    : '—'),
                            Placeholder::make('products_count_display')
                                ->label('Товарів у базі')
                                ->content(fn (Counterparty $record): string => number_format($record->products()->count())),
                        ]),
                        Placeholder::make('last_sync_error_display')
                            ->label('Остання помилка')
                            ->content(fn (Counterparty $record): HtmlString|string => filled($record->last_sync_error)
                                ? new HtmlString('<span class="text-danger-600 dark:text-danger-400">'.e($record->last_sync_error).'</span>')
                                : '—')
                            ->visible(fn (Counterparty $record): bool => filled($record->last_sync_error))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
