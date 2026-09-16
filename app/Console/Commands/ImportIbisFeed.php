<?php

namespace App\Console\Commands;

use App\Services\IbisFeedImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class ImportIbisFeed extends Command
{
    private const FEED_URL = 'https://ibis-gear.com/feed/a0afb4f2-8f09-11f0-94a3-1932629c8f41.xml';

    protected $signature = 'feed:import-ibis {--file= : Path to the downloaded XML feed} {--replace : Replace the current catalog before importing}';

    protected $description = 'Import categories and products from the IBIS Gear XML feed';

    public function handle(IbisFeedImporter $importer): int
    {
        $file = (string) $this->option('file');
        $downloadedFile = false;

        try {
            if ($file === '') {
                $file = tempnam(sys_get_temp_dir(), 'ibis-gear-feed-');
                $downloadedFile = true;
                $this->info('Downloading the IBIS Gear feed...');

                Http::timeout(1200)->connectTimeout(30)->sink($file)->get(self::FEED_URL)->throw();
            }

            $this->info('Importing IBIS Gear products...');

            $result = $importer->import(
                $file,
                (bool) $this->option('replace'),
                fn (int $imported) => $this->line(number_format($imported).' products imported'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($downloadedFile && is_file($file)) {
                unlink($file);
            }
        }

        $this->newLine();
        $this->info(number_format($result['products']).' products imported into '.number_format($result['categories']).' categories.');

        return self::SUCCESS;
    }
}
