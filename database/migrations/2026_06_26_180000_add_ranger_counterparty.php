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

        DB::table('counterparties')->updateOrInsert(
            ['slug' => 'ranger'],
            [
                'name' => 'Ranger',
                'feed_url' => 'https://ranger.ua/public/processed_ranger.xml',
                'feed_format' => 'xml',
                'feed_profile' => 'ranger',
                'is_active' => true,
                'auto_sync' => true,
                'notes' => 'YML фід туристичного спорядження Ranger.',
                'updated_at' => $now,
                ...(! DB::table('counterparties')->where('slug', 'ranger')->exists()
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

        DB::table('counterparties')->where('slug', 'ranger')->delete();
    }
};
