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
        $feedUrl = 'https://opt.travel-extreme.com.ua/index.php?route=extension/feed/unixml/rozetka';

        DB::table('counterparties')->updateOrInsert(
            ['slug' => 'travelextreme'],
            [
                'name' => 'Travel Extreme',
                'feed_url' => $feedUrl,
                'feed_format' => 'xml',
                'feed_profile' => 'travelextreme',
                'is_active' => true,
                'auto_sync' => true,
                'notes' => 'YML фід Travel Extreme: offer id як артикул, stock_quantity, available, українські назви.',
                'updated_at' => $now,
                ...(! DB::table('counterparties')->where('slug', 'travelextreme')->exists()
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

        DB::table('counterparties')->where('slug', 'travelextreme')->delete();
    }
};
