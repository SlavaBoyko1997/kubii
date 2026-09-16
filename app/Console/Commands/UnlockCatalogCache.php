<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class UnlockCatalogCache extends Command
{
    protected $signature = 'catalog:cache-unlock';

    protected $description = 'Примусово знімає блокування фонового прогріву кешу каталогу';

    public function handle(): int
    {
        Cache::lock('catalog:cache-warm:lock')->forceRelease();

        $this->info('Блокування catalog:cache-warm:lock знято.');

        return self::SUCCESS;
    }
}
