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
        $feedUrl = 'https://iidlo.com/content/export/68.xml';

        DB::table('counterparties')->updateOrInsert(
            ['slug' => 'iidlo'],
            [
                'name' => 'ЇDLO',
                'feed_url' => $feedUrl,
                'feed_format' => 'xml',
                'feed_profile' => 'atlantmarket',
                'is_active' => true,
                'auto_sync' => true,
                'notes' => 'YML фід iidlo: vendorCode, vendor, group_id, available.',
                'updated_at' => $now,
                ...(! DB::table('counterparties')->where('slug', 'iidlo')->exists()
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

        DB::table('counterparties')->where('slug', 'iidlo')->delete();
    }
};
