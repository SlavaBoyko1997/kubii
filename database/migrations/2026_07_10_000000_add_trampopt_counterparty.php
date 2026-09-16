<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('counterparties')) {
            return;
        }

        $now = now();
        $feedUrl = 'https://24.ecomm.plus:8080/TrampOpt/tramp_opt_price_new.yml';

        DB::table('counterparties')->updateOrInsert(
            ['slug' => 'trampopt'],
            [
                'name' => 'Tramp Opt',
                'feed_url' => $feedUrl,
                'feed_format' => 'yml',
                'feed_profile' => 'trampopt',
                'is_active' => true,
                'auto_sync' => true,
                'notes' => 'YML фід Tramp Opt (ecomm.plus): quantity_in_stock, group_id, vendor, vendorCode.',
                'updated_at' => $now,
                ...(! DB::table('counterparties')->where('slug', 'trampopt')->exists()
                    ? ['created_at' => $now]
                    : []),
            ],
        );
    }

    public function down(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('counterparties')) {
            return;
        }

        DB::table('counterparties')->where('slug', 'trampopt')->delete();
    }
};
