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
        $feedUrl = 'https://voltmarket.ua/index.php?route=extension/feed/unixml/google';

        DB::table('counterparties')->updateOrInsert(
            ['slug' => 'voltmarket'],
            [
                'name' => 'Voltmarket',
                'feed_url' => $feedUrl,
                'feed_format' => 'atom',
                'feed_profile' => 'voltmarket',
                'is_active' => true,
                'auto_sync' => true,
                'notes' => 'Google/Atom feed Voltmarket. Імпортуються тільки паливні генератори, ДБЖ, інвертори та зарядні пристрої.',
                'updated_at' => $now,
                ...(! DB::table('counterparties')->where('slug', 'voltmarket')->exists()
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

        DB::table('counterparties')->where('slug', 'voltmarket')->delete();
    }
};
