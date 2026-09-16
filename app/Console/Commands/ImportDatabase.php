<?php

namespace App\Console\Commands;

use App\Support\DatabaseBackupService;
use Illuminate\Console\Command;

class ImportDatabase extends Command
{
    protected $signature = 'db:import
                            {path : Шлях до .sql або .sql.gz}
                            {--force : Імпортувати без підтвердження}';

    protected $description = 'Імпортує MySQL-дамп і замінює поточні дані в базі';

    public function handle(DatabaseBackupService $backup): int
    {
        try {
            $path = $backup->resolveImportPath((string) $this->argument('path'));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->warn('Усі поточні дані в базі будуть замінені вмістом файлу:');
        $this->line($path);

        if (! $this->option('force') && ! $this->confirm('Продовжити?', false)) {
            return self::FAILURE;
        }

        try {
            $backup->import($path);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Базу імпортовано. Кеш очищено.');

        return self::SUCCESS;
    }
}
