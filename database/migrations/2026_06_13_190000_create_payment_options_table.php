<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_options', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_enabled')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('payment_options')->insert([
            [
                'code' => 'liqpay_hold',
                'name' => 'Оплата карткою на сайті',
                'description' => 'LiqPay: кошти блокуються на картці до підтвердження менеджером.',
                'is_enabled' => true,
                'sort_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'iban',
                'name' => 'Оплата на IBAN',
                'description' => 'Менеджер надає реквізити після перевірки замовлення.',
                'is_enabled' => true,
                'sort_order' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'cash_on_delivery',
                'name' => 'Післяплата у Новій Пошті',
                'description' => 'Оплата під час отримання замовлення.',
                'is_enabled' => true,
                'sort_order' => 30,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_options');
    }
};
