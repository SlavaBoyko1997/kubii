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
        $feedUrl = 'https://tactic-shop.in.ua/content/export/1c08489b95c8180410568d6f088a8ac6.xml';

        DB::table('counterparties')->updateOrInsert(
            ['slug' => 'tacticshop'],
            [
                'name' => 'Tactic Shop',
                'feed_url' => $feedUrl,
                'feed_format' => 'xml',
                'feed_profile' => 'atlantmarket',
                'is_active' => true,
                'auto_sync' => true,
                'notes' => 'YML фід Tactic Shop: name_ua, vendorCode, vendor, group_id, available.',
                'updated_at' => $now,
                ...(! DB::table('counterparties')->where('slug', 'tacticshop')->exists()
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

        DB::table('counterparties')->where('slug', 'tacticshop')->delete();
    }
};
