<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $latestSixDigitNumber = DB::table('orders')
            ->whereRaw('LENGTH(number) = 6')
            ->max('number');

        DB::table('order_number_sequences')->updateOrInsert(
            ['id' => 1],
            ['current_value' => max(100000, (int) $latestSixDigitNumber)],
        );
    }

    public function down(): void
    {
        // Existing order numbers must remain stable when rolling back.
    }
};
