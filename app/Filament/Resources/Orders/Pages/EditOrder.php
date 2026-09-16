<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Exceptions\LiqPayException;
use App\Exceptions\MonobankException;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Services\LiqPayService;
use App\Services\MonobankService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        $this->record->markAdminReviewed();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewCustomer')
                ->label('Клієнт')
                ->icon('heroicon-o-user')
                ->url(fn (): ?string => $this->record->user_id
                    ? CustomerResource::getUrl('view', ['record' => $this->record->user_id])
                    : null)
                ->visible(fn (): bool => filled($this->record->user_id)),
            Action::make('callCustomer')
                ->label('Подзвонити')
                ->icon('heroicon-o-phone')
                ->url(fn (): ?string => filled($this->record->phone)
                    ? 'tel:'.preg_replace('/\s+/', '', (string) $this->record->phone)
                    : null)
                ->visible(fn (): bool => filled($this->record->phone)),
            Action::make('openPayment')
                ->label('Сторінка оплати')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (): ?string => $this->record->onlinePaymentCheckoutRoute())
                ->openUrlInNewTab()
                ->visible(fn (): bool => $this->record->usesOnlineHoldPayment()
                    && $this->record->payment_status === 'pending'
                    && filled($this->record->onlinePaymentCheckoutRoute())),
            Action::make('captureHold')
                ->label('Списати кошти')
                ->color('success')
                ->icon('heroicon-o-credit-card')
                ->requiresConfirmation()
                ->modalHeading('Списати кошти з холду?')
                ->modalDescription(fn (): string => $this->record->payment_method === 'mono_checkout'
                    ? 'Monobank спише повну суму замовлення. Цю дію не можна скасувати через повторне списання.'
                    : 'LiqPay спише повну суму замовлення. Цю дію не можна скасувати через повторне списання.')
                ->visible(fn (): bool => in_array($this->record->payment_method, ['liqpay_hold', 'mono_checkout'], true)
                    && $this->record->payment_status === 'holded')
                ->action(function (): void {
                    try {
                        match ($this->record->payment_method) {
                            'mono_checkout' => app(MonobankService::class)->finalizeHold($this->record),
                            default => app(LiqPayService::class)->captureHold($this->record),
                        };
                        $this->refreshFormData(array_keys($this->record->getAttributes()));
                        Notification::make()->title('Кошти успішно списано')->success()->send();
                    } catch (LiqPayException|MonobankException $exception) {
                        Notification::make()->title('Не вдалося списати кошти')->body($exception->getMessage())->danger()->send();
                    }
                }),
            Action::make('cancelHold')
                ->label('Скасувати холд')
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->requiresConfirmation()
                ->modalHeading('Скасувати холд?')
                ->modalDescription('Заблоковані кошти буде розблоковано на картці клієнта.')
                ->visible(fn (): bool => in_array($this->record->payment_method, ['liqpay_hold', 'mono_checkout'], true)
                    && $this->record->payment_status === 'holded')
                ->action(function (): void {
                    try {
                        match ($this->record->payment_method) {
                            'mono_checkout' => app(MonobankService::class)->cancelHold($this->record),
                            default => app(LiqPayService::class)->cancelHold($this->record),
                        };
                        $this->refreshFormData(array_keys($this->record->getAttributes()));
                        Notification::make()->title('Холд скасовано')->success()->send();
                    } catch (LiqPayException|MonobankException $exception) {
                        Notification::make()->title('Не вдалося скасувати холд')->body($exception->getMessage())->danger()->send();
                    }
                }),
            DeleteAction::make(),
        ];
    }
}
