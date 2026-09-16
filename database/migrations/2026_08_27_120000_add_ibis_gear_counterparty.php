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
        $feedUrl = 'https://ibis-gear.com/feed/a0afb4f2-8f09-11f0-94a3-1932629c8f41.xml';

        DB::table('counterparties')->updateOrInsert(
            ['slug' => 'ibis-gear'],
            [
                'name' => 'IBIS Gear',
                'feed_url' => $feedUrl,
                'feed_format' => 'xml',
                'feed_profile' => 'ibis',
                'is_active' => true,
                'auto_sync' => true,
                'notes' => 'Google Merchant RSS фід IBIS Gear: g:id, g:brand, g:availability, g:prop, українські й російські назви/описи.',
                'updated_at' => $now,
                ...(! DB::table('counterparties')->where('slug', 'ibis-gear')->exists()
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

        DB::table('counterparties')->where('slug', 'ibis-gear')->delete();
    }
};
