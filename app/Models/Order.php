<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    protected $fillable = [
        'number',
        'order_type',
        'user_id',
        'customer_name',
        'phone',
        'email',
        'city',
        'delivery_address',
        'delivery_type',
        'nova_poshta_city_ref',
        'nova_poshta_city_name',
        'nova_poshta_warehouse_ref',
        'nova_poshta_warehouse_name',
        'nova_poshta_warehouse_number',
        'nova_poshta_warehouse_address',
        'nova_poshta_street_ref',
        'nova_poshta_street_name',
        'nova_poshta_building',
        'nova_poshta_flat',
        'comment',
        'status',
        'admin_reviewed_at',
        'payment_method',
        'payment_status',
        'payment_amount',
        'payment_currency',
        'liqpay_payment_id',
        'liqpay_transaction_id',
        'liqpay_hold_at',
        'liqpay_paid_at',
        'liqpay_cancelled_at',
        'liqpay_response',
        'mono_invoice_id',
        'mono_hold_at',
        'mono_paid_at',
        'mono_cancelled_at',
        'mono_response',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'payment_amount' => 'decimal:2',
            'liqpay_hold_at' => 'datetime',
            'liqpay_paid_at' => 'datetime',
            'liqpay_cancelled_at' => 'datetime',
            'liqpay_response' => 'array',
            'mono_hold_at' => 'datetime',
            'mono_paid_at' => 'datetime',
            'mono_cancelled_at' => 'datetime',
            'mono_response' => 'array',
            'admin_reviewed_at' => 'datetime',
        ];
    }

    public function isAdminReviewed(): bool
    {
        return $this->admin_reviewed_at !== null;
    }

    public function markAdminReviewed(): void
    {
        if ($this->isAdminReviewed()) {
            return;
        }

        $this->forceFill(['admin_reviewed_at' => now()])->save();
    }

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            $order->number = self::nextNumber();
        });
    }

    public static function nextNumber(): string
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('UPDATE order_number_sequences SET current_value = LAST_INSERT_ID(current_value + 1) WHERE id = 1');

            return (string) DB::scalar('SELECT LAST_INSERT_ID()');
        }

        DB::table('order_number_sequences')->where('id', 1)->increment('current_value');

        return (string) DB::table('order_number_sequences')->where('id', 1)->value('current_value');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function usesOnlineHoldPayment(): bool
    {
        return in_array($this->payment_method, ['liqpay_hold', 'mono_checkout'], true);
    }

    public function onlinePaymentCheckoutRoute(): ?string
    {
        return match ($this->payment_method) {
            'liqpay_hold' => route('payment.liqpay.checkout', $this, false),
            'mono_checkout' => route('payment.mono.checkout', $this, false),
            default => null,
        };
    }
}
