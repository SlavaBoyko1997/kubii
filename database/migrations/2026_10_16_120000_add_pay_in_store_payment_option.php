<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('payment_options')->where('code', 'pay_in_store')->exists()) {
            DB::table('payment_options')->insert([
                'code' => 'pay_in_store',
                'name' => 'Оплата в магазині',
                'description' => 'Готівка або картка при самовивозі зі складу в Борисполі.',
                'is_enabled' => true,
                'sort_order' => 35,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::forget('checkout:payment-options');
    }

    public function down(): void
    {
        DB::table('payment_options')->where('code', 'pay_in_store')->delete();
        Cache::forget('checkout:payment-options');
    }
};
