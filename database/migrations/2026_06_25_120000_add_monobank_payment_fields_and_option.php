<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('mono_invoice_id')->nullable()->after('liqpay_response');
            $table->timestamp('mono_hold_at')->nullable()->after('mono_invoice_id');
            $table->timestamp('mono_paid_at')->nullable()->after('mono_hold_at');
            $table->timestamp('mono_cancelled_at')->nullable()->after('mono_paid_at');
            $table->json('mono_response')->nullable()->after('mono_cancelled_at');
        });

        if (! DB::table('payment_options')->where('code', 'mono_checkout')->exists()) {
            DB::table('payment_options')->insert([
                'code' => 'mono_checkout',
                'name' => 'Онлайн-оплата карткою',
                'description' => 'Monobank Checkout: кошти блокуються на картці до підтвердження менеджером.',
                'is_enabled' => true,
                'sort_order' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('payment_options')->where('code', 'mono_checkout')->delete();

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'mono_invoice_id',
                'mono_hold_at',
                'mono_paid_at',
                'mono_cancelled_at',
                'mono_response',
            ]);
        });
    }
};
