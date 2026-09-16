<?php

use App\Models\Order;
use App\Support\GuestCustomerProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_guest')->default(false)->after('is_admin');
        });

        $profile = app(GuestCustomerProfile::class);

        Order::query()
            ->whereNull('user_id')
            ->orderBy('id')
            ->each(fn (Order $order): bool => $profile->attachOrder($order));
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_guest');
        });
    }
};
