<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Order;
use App\Support\OrderPresentation;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Замовлення')
                    ->icon(Heroicon::OutlinedShoppingCart)
                    ->schema([
                        Grid::make(3)->schema([
                            Placeholder::make('number_display')
                                ->label('Номер')
                                ->content(fn (Order $record): string => '#'.$record->number),
                            Placeholder::make('created_at_display')
                                ->label('Створено')
                                ->content(fn (Order $record): string => $record->created_at?->format('d.m.Y H:i') ?? '—'),
                            Placeholder::make('order_type_display')
                                ->label('Тип')
                                ->content(fn (Order $record): string => OrderPresentation::orderTypeLabel($record->order_type)),
                        ]),
                        Grid::make(2)->schema([
                            Select::make('status')
                                ->label('Статус замовлення')
                                ->options([
                                    'new' => 'Нове',
                                    'confirmed' => 'Підтверджене',
                                    'shipped' => 'Відправлене',
                                    'completed' => 'Виконане',
                                    'cancelled' => 'Скасоване',
                                ])
                                ->required(),
                            Placeholder::make('total_display')
                                ->label('Сума замовлення')
                                ->content(fn (Order $record): string => OrderPresentation::formatMoney($record->total)),
                        ]),
                    ]),

                Section::make('Покупець')
                    ->icon(Heroicon::OutlinedUser)
                    ->schema([
                        Grid::make(2)->schema([
                            Placeholder::make('customer_name_display')
                                ->label('Ім\'я')
                                ->content(fn (Order $record): string => $record->customer_name ?: 'Уточнити телефоном'),
                            Placeholder::make('registered_customer')
                                ->label('Акаунт на сайті')
                                ->content(function (Order $record): HtmlString|string {
                                    if (! $record->user_id) {
                                        return 'Гість (без реєстрації)';
                                    }

                                    $customer = $record->user;

                                    if (! $customer) {
                                        return 'Профіль видалено';
                                    }

                                    $url = CustomerResource::getUrl('view', ['record' => $customer]);
                                    $label = $customer->is_guest
                                        ? 'Відкрити профіль гостя'
                                        : 'Відкрити профіль клієнта';

                                    return new HtmlString(
                                        '<a href="'.e($url).'" class="text-primary-600 hover:underline dark:text-primary-400">'.e($label).'</a>'
                                    );
                                }),
                            Placeholder::make('phone_display')
                                ->label('Телефон')
                                ->content(function (Order $record): HtmlString|string {
                                    if (! filled($record->phone)) {
                                        return '—';
                                    }

                                    $phone = e($record->phone);

                                    return new HtmlString(
                                        '<a href="tel:'.e(preg_replace('/\s+/', '', $record->phone)).'" class="text-primary-600 hover:underline dark:text-primary-400">'.$phone.'</a>'
                                    );
                                }),
                            Placeholder::make('email_display')
                                ->label('Email')
                                ->content(function (Order $record): HtmlString|string {
                                    if (! filled($record->email)) {
                                        return '—';
                                    }

                                    $email = e($record->email);

                                    return new HtmlString(
                                        '<a href="mailto:'.$email.'" class="text-primary-600 hover:underline dark:text-primary-400">'.$email.'</a>'
                                    );
                                }),
                        ]),
                    ]),

                Section::make('Доставка')
                    ->icon(Heroicon::OutlinedTruck)
                    ->visible(fn (Order $record): bool => filled($record->delivery_type)
                        || filled($record->delivery_address)
                        || filled($record->city))
                    ->schema([
                        Placeholder::make('delivery_type_display')
                            ->label('Спосіб доставки')
                            ->content(fn (Order $record): string => OrderPresentation::deliveryTypeLabel($record->delivery_type)
                                ?? ($record->delivery_address ? 'Доставка' : '—')),
                        Placeholder::make('delivery_summary')
                            ->label('Деталі')
                            ->content(function (Order $record): HtmlString|string {
                                $fields = OrderPresentation::deliveryFields($record);

                                if ($fields === []) {
                                    return '—';
                                }

                                $items = collect($fields)->map(function (array $field): string {
                                    $wide = ($field['wide'] ?? false) ? ' style="grid-column: 1 / -1;"' : '';
                                    $label = e($field['label']);
                                    $value = e($field['value']);

                                    return <<<HTML
                                        <div{$wide} class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                                            <div class="text-xs text-gray-500 dark:text-gray-400">{$label}</div>
                                            <div class="mt-1 font-medium text-gray-950 dark:text-white">{$value}</div>
                                        </div>
                                    HTML;
                                })->implode('');

                                return new HtmlString(
                                    '<div class="grid gap-3 sm:grid-cols-2">'.$items.'</div>'
                                );
                            })
                            ->columnSpanFull(),
                    ]),

                Section::make('Службові поля Нової Пошти')
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->collapsed()
                    ->visible(fn (Order $record): bool => OrderPresentation::isNovaPoshtaDelivery($record))
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('nova_poshta_city_ref')->label('CityRef')->disabled(),
                            TextInput::make('nova_poshta_city_name')->label('Місто (API)')->disabled(),
                            TextInput::make('nova_poshta_warehouse_ref')->label('WarehouseRef')->disabled(),
                            TextInput::make('nova_poshta_warehouse_name')->label('Точка (API)')->disabled(),
                            TextInput::make('nova_poshta_street_ref')->label('StreetRef')->disabled(),
                            TextInput::make('nova_poshta_street_name')->label('Вулиця (API)')->disabled(),
                        ]),
                    ]),

                Section::make('Оплата')
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->schema([
                        Grid::make(3)->schema([
                            Placeholder::make('payment_method_display')
                                ->label('Спосіб оплати')
                                ->content(fn (Order $record): string => OrderPresentation::paymentMethodLabel($record->payment_method)),
                            Placeholder::make('payment_status_display')
                                ->label('Статус оплати')
                                ->content(fn (Order $record): string => OrderPresentation::paymentStatusLabel($record->payment_status)),
                            Placeholder::make('payment_amount_display')
                                ->label('Сума онлайн-оплати')
                                ->content(fn (Order $record): string => $record->usesOnlineHoldPayment()
                                    ? OrderPresentation::formatMoney($record->payment_amount)
                                    : '—'),
                        ]),
                        Placeholder::make('payment_checkout_link')
                            ->label('Посилання на оплату')
                            ->visible(fn (Order $record): bool => $record->usesOnlineHoldPayment()
                                && $record->payment_status === 'pending'
                                && filled($record->onlinePaymentCheckoutRoute()))
                            ->content(function (Order $record): HtmlString {
                                $url = e($record->onlinePaymentCheckoutRoute());

                                return new HtmlString(
                                    '<a href="'.$url.'" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">Відкрити сторінку оплати ↗</a>'
                                );
                            }),
                    ]),

                Section::make('LiqPay')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->visible(fn (Order $record): bool => $record->payment_method === 'liqpay_hold')
                    ->collapsed(fn (Order $record): bool => $record->payment_status === 'pending')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('liqpay_payment_id')->label('Payment ID')->disabled(),
                            TextInput::make('liqpay_transaction_id')->label('Transaction ID')->disabled(),
                            Placeholder::make('liqpay_hold_at_display')
                                ->label('Дата холду')
                                ->content(fn (Order $record): string => $record->liqpay_hold_at?->format('d.m.Y H:i') ?? '—'),
                            Placeholder::make('liqpay_paid_at_display')
                                ->label('Дата списання')
                                ->content(fn (Order $record): string => $record->liqpay_paid_at?->format('d.m.Y H:i') ?? '—'),
                            Placeholder::make('liqpay_cancelled_at_display')
                                ->label('Дата скасування')
                                ->content(fn (Order $record): string => $record->liqpay_cancelled_at?->format('d.m.Y H:i') ?? '—'),
                        ]),
                        Textarea::make('liqpay_response')
                            ->label('Остання відповідь LiqPay')
                            ->formatStateUsing(fn (mixed $state): string => is_array($state)
                                ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                : (string) $state)
                            ->rows(8)
                            ->disabled()
                            ->columnSpanFull(),
                    ]),

                Section::make('Monobank')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->visible(fn (Order $record): bool => $record->payment_method === 'mono_checkout')
                    ->collapsed(fn (Order $record): bool => $record->payment_status === 'pending')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('mono_invoice_id')->label('Invoice ID')->disabled(),
                            Placeholder::make('mono_hold_at_display')
                                ->label('Дата холду')
                                ->content(fn (Order $record): string => $record->mono_hold_at?->format('d.m.Y H:i') ?? '—'),
                            Placeholder::make('mono_paid_at_display')
                                ->label('Дата списання')
                                ->content(fn (Order $record): string => $record->mono_paid_at?->format('d.m.Y H:i') ?? '—'),
                            Placeholder::make('mono_cancelled_at_display')
                                ->label('Дата скасування')
                                ->content(fn (Order $record): string => $record->mono_cancelled_at?->format('d.m.Y H:i') ?? '—'),
                        ]),
                        Textarea::make('mono_response')
                            ->label('Остання відповідь Monobank')
                            ->formatStateUsing(fn (mixed $state): string => is_array($state)
                                ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                : (string) $state)
                            ->rows(8)
                            ->disabled()
                            ->columnSpanFull(),
                    ]),

                Section::make('Товари')
                    ->icon(Heroicon::OutlinedCube)
                    ->schema([
                        Placeholder::make('items_table')
                            ->hiddenLabel()
                            ->content(fn (Order $record): HtmlString => new HtmlString(
                                view('filament.forms.components.order-items-table', ['order' => $record])->render()
                            ))
                            ->columnSpanFull(),
                    ]),

                Section::make('Коментар')
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->visible(fn (Order $record): bool => filled($record->comment))
                    ->schema([
                        Placeholder::make('comment_display')
                            ->hiddenLabel()
                            ->content(fn (Order $record): string => (string) $record->comment)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
