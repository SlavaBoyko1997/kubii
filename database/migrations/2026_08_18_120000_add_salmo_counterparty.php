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
        $feedUrl = 'https://salmo.ua/content/export/d88305e5d77b69741987dd034a0c66e6.xml';

        DB::table('counterparties')->updateOrInsert(
            ['slug' => 'salmo'],
            [
                'name' => 'Salmo',
                'feed_url' => $feedUrl,
                'feed_format' => 'xml',
                'feed_profile' => 'salmo',
                'is_active' => true,
                'auto_sync' => true,
                'notes' => 'YML фід Salmo: vendorCode, vendor, group_id, available, українські назви й описи.',
                'updated_at' => $now,
                ...(! DB::table('counterparties')->where('slug', 'salmo')->exists()
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

        DB::table('counterparties')->where('slug', 'salmo')->delete();
    }
};
