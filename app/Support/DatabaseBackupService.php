<?php

namespace App\Support;

use App\Support\CatalogCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class DatabaseBackupService
{
    /**
     * @return array{path: string, size: int}
     */
    public function export(): array
    {
        $this->ensureEnabled();

        $directory = storage_path('app/private/database-exports');
        File::ensureDirectoryExists($directory);

        $sqlPath = $directory.'/kubii-'.now()->format('Ymd-His').'.sql';
        $gzPath = $sqlPath.'.gz';

        try {
            $this->runDump($sqlPath);
            $this->gzipFile($sqlPath, $gzPath);
        } finally {
            @unlink($sqlPath);
        }

        if (! is_file($gzPath)) {
            throw new RuntimeException('Не вдалося створити файл експорту.');
        }

        return [
            'path' => $gzPath,
            'size' => (int) filesize($gzPath),
        ];
    }

    public function import(string $absolutePath): void
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('Файл імпорту не знайдено.');
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        if (! in_array($extension, ['sql', 'gz'], true)) {
            throw new RuntimeException('Підтримуються лише файли .sql та .sql.gz.');
        }

        $this->ensureEnabled();

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        if ($extension === 'gz') {
            $this->importGzipDump($absolutePath);
        } else {
            $this->importSqlDump($absolutePath);
        }

        Artisan::call('optimize:clear');
        app(CatalogCache::class)->invalidateAndLog(
            \App\Models\CatalogCacheLog::TRIGGER_SYSTEM,
            'Імпорт бази даних',
        );
    }

    public function resolveImportPath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new RuntimeException('Шлях до файлу імпорту не вказано.');
        }

        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        $realPath = realpath($path);

        if ($realPath === false || ! is_file($realPath)) {
            throw new RuntimeException('Файл імпорту не знайдено.');
        }

        $allowedRoots = array_values(array_filter(array_map(
            static fn (string $root): ?string => realpath($root) ?: null,
            [
                storage_path('app'),
            ],
        )));

        $allowed = false;

        foreach ($allowedRoots as $root) {
            $prefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

            if ($realPath === $root || str_starts_with($realPath, $prefix)) {
                $allowed = true;

                break;
            }
        }

        if (! $allowed) {
            if (app()->environment('local') && is_readable($realPath)) {
                return $realPath;
            }

            throw new RuntimeException('Шлях поза дозволеною директорією (storage/app).');
        }

        return $realPath;
    }

    /**
     * @return array{
     *     connection: string,
     *     database: string,
     *     tables: int,
     *     dump_available: bool,
     *     via_docker: bool,
     *     upload_max_filesize: string,
     *     post_max_size: string,
     *     upload_limit_ok: bool,
     *     livewire_tmp_writable: bool,
     *     import_directory: string,
     * }
     */
    public function status(): array
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database", '');

        $tables = 0;

        try {
            $tables = count(DB::select('SHOW TABLES'));
        } catch (\Throwable) {
        }

        $importDirectory = storage_path('app/private/database-imports');
        File::ensureDirectoryExists($importDirectory);

        $livewireTmpCandidates = [
            storage_path('app/private/livewire-tmp'),
            storage_path('app/livewire-tmp'),
            storage_path('framework/livewire-tmp'),
        ];

        $livewireTmpWritable = false;

        foreach ($livewireTmpCandidates as $directory) {
            File::ensureDirectoryExists($directory);

            if (is_writable($directory)) {
                $livewireTmpWritable = true;

                break;
            }
        }

        $uploadLimitBytes = $this->parseIniSize((string) ini_get('upload_max_filesize'));

        return [
            'connection' => $connection,
            'database' => $database,
            'tables' => $tables,
            'dump_available' => $this->canDump(),
            'via_docker' => $this->usesDockerMysqlCli(),
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'upload_limit_ok' => $uploadLimitBytes >= 512 * 1024 * 1024,
            'livewire_tmp_writable' => $livewireTmpWritable,
            'import_directory' => $importDirectory,
        ];
    }

    public function canDump(): bool
    {
        return $this->resolveBinary('mysqldump') !== null
            || $this->usesDockerMysqlCli();
    }

    private function ensureEnabled(): void
    {
        if (! config('database.admin_backup.enabled', true)) {
            throw new RuntimeException('Резервне копіювання через адмінку вимкнено.');
        }

        if (config('database.default') !== 'mysql') {
            throw new RuntimeException('Підтримується лише MySQL.');
        }

        if (! $this->canDump()) {
            throw new RuntimeException('mysqldump недоступний. Встановіть mysql-client або запустіть Docker MySQL.');
        }
    }

    private function runDump(string $outputPath): void
    {
        $defaultsFile = $this->prepareDefaultsFile();

        try {
            $command = [
                ...$this->mysqlCliPrefix('mysqldump'),
                ...$this->mysqlAuthArgs($defaultsFile),
                '--single-transaction',
                '--routines',
                '--triggers',
                '--no-tablespaces',
                $this->databaseName(),
            ];

            $process = new Process($command, base_path(), $this->mysqlEnv());
            $process->setTimeout(null);
            $process->run(function (string $type, string $buffer) use ($outputPath): void {
                if ($type === Process::OUT) {
                    file_put_contents($outputPath, $buffer, FILE_APPEND);
                }
            });

            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'mysqldump завершився з помилкою.');
            }
        } finally {
            $this->cleanupDefaultsFile($defaultsFile);
        }
    }

    private function importSqlDump(string $inputPath): void
    {
        $defaultsFile = $this->prepareDefaultsFile();

        try {
            $command = [
                ...$this->mysqlCliPrefix('mysql'),
                ...$this->mysqlAuthArgs($defaultsFile),
                $this->databaseName(),
            ];

            $process = Process::fromShellCommandline(
                implode(' ', array_map('escapeshellarg', $command)).' < '.escapeshellarg($inputPath),
                base_path(),
                $this->mysqlEnv(),
            );
            $process->setTimeout(null);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'mysql import завершився з помилкою.');
            }
        } finally {
            $this->cleanupDefaultsFile($defaultsFile);
        }
    }

    private function importGzipDump(string $inputPath): void
    {
        if ($this->resolveBinary('gunzip') === null) {
            throw new RuntimeException('gunzip недоступний для імпорту .sql.gz.');
        }

        $defaultsFile = $this->prepareDefaultsFile();

        try {
            $command = [
                ...$this->mysqlCliPrefix('mysql'),
                ...$this->mysqlAuthArgs($defaultsFile),
                $this->databaseName(),
            ];

            $shell = sprintf(
                'gunzip -c %s | %s',
                escapeshellarg($inputPath),
                implode(' ', array_map('escapeshellarg', $command)),
            );

            $process = Process::fromShellCommandline($shell, base_path(), $this->mysqlEnv());
            $process->setTimeout(null);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Імпорт .sql.gz завершився з помилкою.');
            }
        } finally {
            $this->cleanupDefaultsFile($defaultsFile);
        }
    }

    private function gzipFile(string $sourcePath, string $targetPath): void
    {
        if ($this->resolveBinary('gzip') === null) {
            throw new RuntimeException('gzip недоступний.');
        }

        $process = Process::fromShellCommandline(
            'gzip -c '.escapeshellarg($sourcePath).' > '.escapeshellarg($targetPath),
            base_path(),
        );
        $process->mustRun();
    }

    /**
     * @return list<string>
     */
    private function mysqlCliPrefix(string $binary): array
    {
        if ($this->usesDockerMysqlCli()) {
            $prefix = [
                'docker', 'compose',
                '-f', $this->dockerComposeFile(),
                'exec', '-T',
            ];

            $password = (string) config('database.connections.mysql.password');

            if ($password !== '') {
                $prefix[] = '-e';
                $prefix[] = 'MYSQL_PWD='.$password;
            }

            return [
                ...$prefix,
                'mysql',
                $binary,
            ];
        }

        $resolved = $this->resolveBinary($binary);

        if ($resolved === null) {
            throw new RuntimeException("Не знайдено {$binary}.");
        }

        return [$resolved];
    }

    /**
     * @return list<string>
     */
    private function mysqlAuthArgs(?string $defaultsFile): array
    {
        if ($this->usesDockerMysqlCli()) {
            return [
                '-u', (string) config('database.connections.mysql.username'),
            ];
        }

        return ['--defaults-extra-file='.($defaultsFile ?? $this->writeDefaultsFile())];
    }

    private function prepareDefaultsFile(): ?string
    {
        return $this->usesDockerMysqlCli() ? null : $this->writeDefaultsFile();
    }

    private function cleanupDefaultsFile(?string $defaultsFile): void
    {
        if (is_string($defaultsFile) && is_file($defaultsFile)) {
            @unlink($defaultsFile);
        }
    }

    /**
     * @return array<string, string>
     */
    private function mysqlEnv(): array
    {
        if ($this->usesDockerMysqlCli()) {
            return [];
        }

        $password = (string) config('database.connections.mysql.password');

        if ($password === '') {
            return [];
        }

        return ['MYSQL_PWD' => $password];
    }

    private function databaseName(): string
    {
        return (string) config('database.connections.mysql.database');
    }

    private function usesDockerMysqlCli(): bool
    {
        if ($this->resolveBinary('mysqldump') !== null) {
            return false;
        }

        return $this->resolveBinary('docker') !== null
            && is_file($this->dockerComposeFile());
    }

    private function dockerComposeFile(): string
    {
        $configured = config('database.admin_backup.docker_compose_file');

        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $prod = base_path('docker-compose.prod.yml');
        $local = base_path('docker-compose.yml');

        if (app()->environment('production') && is_file($prod)) {
            return $prod;
        }

        return is_file($local) ? $local : $prod;
    }

    private function writeDefaultsFile(): string
    {
        $config = config('database.connections.mysql');
        $path = tempnam(sys_get_temp_dir(), 'kubii-mysql-');

        if ($path === false) {
            throw new RuntimeException('Не вдалося створити тимчасовий конфіг MySQL.');
        }

        $contents = implode(PHP_EOL, [
            '[client]',
            'host='.($config['host'] ?? '127.0.0.1'),
            'port='.($config['port'] ?? 3306),
            'user='.($config['username'] ?? ''),
            'password='.($config['password'] ?? ''),
            '',
        ]);

        file_put_contents($path, $contents);
        chmod($path, 0600);

        return $path;
    }

    private function resolveBinary(string $binary): ?string
    {
        $process = Process::fromShellCommandline('command -v '.escapeshellarg($binary));
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $path = trim($process->getOutput());

        return $path !== '' ? $path : null;
    }

    private function parseIniSize(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }
}
