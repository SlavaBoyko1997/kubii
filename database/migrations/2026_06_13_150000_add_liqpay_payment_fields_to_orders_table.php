<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('payment_status', 20)->default('pending')->after('payment_method')->index();
            $table->decimal('payment_amount', 12, 2)->nullable()->after('payment_status');
            $table->string('payment_currency', 3)->default('UAH')->after('payment_amount');
            $table->string('liqpay_payment_id')->nullable()->after('payment_currency')->index();
            $table->string('liqpay_transaction_id')->nullable()->after('liqpay_payment_id');
            $table->timestamp('liqpay_hold_at')->nullable()->after('liqpay_transaction_id');
            $table->timestamp('liqpay_paid_at')->nullable()->after('liqpay_hold_at');
            $table->timestamp('liqpay_cancelled_at')->nullable()->after('liqpay_paid_at');
            $table->json('liqpay_response')->nullable()->after('liqpay_cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['payment_status']);
            $table->dropIndex(['liqpay_payment_id']);
            $table->dropColumn([
                'payment_status',
                'payment_amount',
                'payment_currency',
                'liqpay_payment_id',
                'liqpay_transaction_id',
                'liqpay_hold_at',
                'liqpay_paid_at',
                'liqpay_cancelled_at',
                'liqpay_response',
            ]);
        });
    }
};
