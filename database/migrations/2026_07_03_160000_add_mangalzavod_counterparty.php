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
        $feedUrl = 'https://mangalzavod.com.ua/products_feed.xml?hash_tag=8368a75acdbd0488a2d8a18e3d8f3e30&sales_notes=&product_ids=&label_ids=12226389&exclude_fields=&html_description=0&yandex_cpa=&process_presence_sure=&languages=uk%2Cru&extra_fields=&group_ids=';

        DB::table('counterparties')->updateOrInsert(
            ['slug' => 'mangalzavod'],
            [
                'name' => 'Mangal Zavod',
                'feed_url' => $feedUrl,
                'feed_format' => 'xml',
                'feed_profile' => 'atlantmarket',
                'is_active' => true,
                'auto_sync' => true,
                'notes' => 'YML фід Mangal Zavod (Prom): name_ua, vendorCode, vendor, available.',
                'updated_at' => $now,
                ...(! DB::table('counterparties')->where('slug', 'mangalzavod')->exists()
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

        DB::table('counterparties')->where('slug', 'mangalzavod')->delete();
    }
};
