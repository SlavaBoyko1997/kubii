<?php

namespace App\Console\Commands;

use App\Services\GoogleMerchantFeedGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class GenerateGoogleMerchantFeed extends Command
{
    protected $signature = 'merchant:feed:generate';

    protected $description = 'Generate the static Google Merchant Center product feed';

    public function handle(GoogleMerchantFeedGenerator $generator): int
    {
        $lock = Cache::lock('google-merchant-feed:generate', 3600);

        if (! $lock->get()) {
            $this->warn('Google Merchant feed generation is already running.');

            return self::FAILURE;
        }

        try {
            $this->info('Generating Google Merchant feed...');
            $result = $generator->generate();

            $this->info('Feed generated successfully.');
            $this->table(['Products', 'Skipped', 'Size', 'Path'], [[
                number_format($result['products']),
                number_format($result['skipped']),
                number_format($result['bytes']).' bytes',
                $result['path'],
            ]]);

            foreach ($result['skipped_reasons'] as $reason => $count) {
                $this->line("Skipped {$reason}: ".number_format($count));
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
