<?php

namespace App\Filament\Pages;

use App\Support\DatabaseBackupService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

class DatabaseBackup extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $navigationLabel = 'База даних';

    protected static string|\UnitEnum|null $navigationGroup = 'Система';

    protected static ?int $navigationSort = 95;

    protected string $view = 'filament.pages.database-backup';

    /** @var array<string, mixed> */
    public array $status = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return config('database.admin_backup.enabled', true) && parent::shouldRegisterNavigation();
    }

    public function mount(DatabaseBackupService $backup): void
    {
        $this->status = $backup->status();

        if ($message = session()->pull('database-backup-error')) {
            Notification::make()
                ->danger()
                ->title('Не вдалося експортувати базу')
                ->body($message)
                ->send();
        }
    }

    public function getTitle(): string
    {
        return 'База даних';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Експорт .sql.gz')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success')
                ->visible(fn (): bool => (bool) ($this->status['dump_available'] ?? false))
                ->url(fn (): string => route('filament.admin.database-backup.export')),
            Action::make('import')
                ->label('Імпорт')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('danger')
                ->visible(fn (): bool => (bool) ($this->status['dump_available'] ?? false))
                ->modalHeading('Імпорт бази даних')
                ->modalDescription('Усі поточні дані в базі будуть замінені вмістом файлу. Спочатку зробіть експорт.')
                ->form([
                    FileUpload::make('dump')
                        ->label('Файл .sql або .sql.gz')
                        ->disk('local')
                        ->directory('database-imports')
                        ->visibility('private')
                        ->maxSize(512 * 1024)
                        ->helperText(fn (): string => 'До 512 МБ. Поточний ліміт PHP: upload_max_filesize='.(string) ($this->status['upload_max_filesize'] ?? '?').', post_max_size='.(string) ($this->status['post_max_size'] ?? '?').'.')
                        ->validationMessages([
                            'max' => 'Файл занадто великий (максимум 512 МБ).',
                            'uploaded' => 'PHP не прийняв файл. Зазвичай upload_max_filesize або post_max_size замалі (потрібно ≥512M). Скопіюйте файл на сервер і вкажіть шлях нижче.',
                        ])
                        ->requiredWithout('server_path')
                        ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                            if ($value === null || $value === '') {
                                return;
                            }

                            $paths = is_array($value) ? $value : [$value];

                            foreach ($paths as $path) {
                                if ($path instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
                                    $extension = strtolower($path->getClientOriginalExtension());
                                } elseif (is_string($path)) {
                                    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                                } else {
                                    continue;
                                }

                                if (! in_array($extension, ['sql', 'gz'], true)) {
                                    $fail('Дозволені лише файли .sql та .sql.gz.');
                                }
                            }
                        }),
                    TextInput::make('server_path')
                        ->label('Або шлях до файлу')
                        ->placeholder('/Users/you/Downloads/dump.sql.gz')
                        ->helperText('Якщо завантаження через браузер не працює — вкажіть повний шлях (локально) або storage/app/private/database-imports/… на сервері.')
                        ->requiredWithout('dump'),
                    TextInput::make('confirm_database')
                        ->label('Підтвердження')
                        ->helperText(fn (): string => 'Введіть назву бази: '.(string) ($this->status['database'] ?? ''))
                        ->required()
                        ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                            if ($value !== (string) config('database.connections.mysql.database')) {
                                $fail('Назва бази не співпадає.');
                            }
                        }),
                ])
                ->action(function (array $data): void {
                    $backup = app(DatabaseBackupService::class);
                    $deleteAfterImport = false;
                    $absolutePath = null;

                    if (filled($data['server_path'] ?? null)) {
                        try {
                            $absolutePath = $backup->resolveImportPath((string) $data['server_path']);
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Невірний шлях')
                                ->body($exception->getMessage())
                                ->send();

                            return;
                        }
                    } else {
                        $storedPath = $data['dump'] ?? null;

                        if (is_array($storedPath)) {
                            $storedPath = $storedPath[0] ?? null;
                        }

                        if (! is_string($storedPath) || $storedPath === '') {
                            Notification::make()
                                ->danger()
                                ->title('Файл не обрано')
                                ->send();

                            return;
                        }

                        $absolutePath = Storage::disk('local')->path($storedPath);
                        $deleteAfterImport = true;
                    }

                    try {
                        $backup->import($absolutePath);

                        if ($deleteAfterImport && isset($storedPath) && is_string($storedPath)) {
                            Storage::disk('local')->delete($storedPath);
                        }

                        $this->status = $backup->status();

                        Notification::make()
                            ->success()
                            ->title('Базу імпортовано')
                            ->body('Кеш очищено. За потреби прогрійте кеш каталогу.')
                            ->send();
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Помилка імпорту')
                            ->body($exception->getMessage())
                            ->send();
                    }
                }),
        ];
    }
}
