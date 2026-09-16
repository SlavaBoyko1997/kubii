<x-filament-panels::page>
    <style>
        .db-panel {
            max-width: 820px;
            padding: 24px;
            border: 1px solid rgba(148, 163, 184, .25);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 1px 4px rgba(15, 23, 42, .06);
        }

        .db-panel h2 {
            margin: 0;
            color: #17211c;
            font-size: 20px;
            font-weight: 750;
        }

        .db-panel p {
            margin: 8px 0 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.55;
        }

        .db-stats {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin-top: 22px;
        }

        .db-stats div {
            padding: 14px 16px;
            border-radius: 12px;
            background: #f8faf9;
            border: 1px solid rgba(148, 163, 184, .18);
        }

        .db-stats small {
            display: block;
            color: #64748b;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .db-stats strong {
            display: block;
            margin-top: 6px;
            color: #17211c;
            font-size: 15px;
        }

        .db-warning {
            margin-top: 18px;
            padding: 12px 14px;
            border-radius: 12px;
            background: rgba(234, 179, 8, .12);
            color: #92400e;
            font-size: 13px;
            line-height: 1.5;
        }

        .db-error {
            margin-top: 18px;
            padding: 12px 14px;
            border-radius: 12px;
            background: rgba(220, 38, 38, .08);
            color: #b91c1c;
            font-size: 13px;
            line-height: 1.5;
        }

        @media (max-width: 640px) {
            .db-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="db-panel">
        <h2>Резервна копія MySQL</h2>
        <p>
            Експорт створює стиснутий дамп <code>.sql.gz</code>. Імпорт повністю замінює поточні дані в базі
            <strong>{{ $status['database'] ?? '—' }}</strong>.
        </p>

        <div class="db-stats">
            <div>
                <small>З’єднання</small>
                <strong>{{ $status['connection'] ?? '—' }}</strong>
            </div>
            <div>
                <small>База даних</small>
                <strong>{{ $status['database'] ?? '—' }}</strong>
            </div>
            <div>
                <small>Таблиць</small>
                <strong>{{ number_format((int) ($status['tables'] ?? 0)) }}</strong>
            </div>
            <div>
                <small>Режим CLI</small>
                <strong>
                    @if($status['dump_available'] ?? false)
                        {{ ($status['via_docker'] ?? false) ? 'Docker MySQL' : 'Локальний mysqldump' }}
                    @else
                        Недоступно
                    @endif
                </strong>
            </div>
            <div>
                <small>PHP upload</small>
                <strong>{{ $status['upload_max_filesize'] ?? '—' }} / {{ $status['post_max_size'] ?? '—' }}</strong>
            </div>
        </div>

        @if(! ($status['upload_limit_ok'] ?? true))
            <div class="db-error">
                <strong>Ліміт завантаження PHP замалий.</strong>
                Поточні значення: <code>upload_max_filesize={{ $status['upload_max_filesize'] ?? '—' }}</code>,
                <code>post_max_size={{ $status['post_max_size'] ?? '—' }}</code>.
                Потрібно ≥512M. Локально перезапустіть <code>composer dev</code>.
                На сервері додайте <code>docker/php/uploads.ini</code> у Dockerfile і перезберіть PHP-контейнер.
                Або скопіюйте дамп у <code>storage/app/private/database-imports/</code> і вкажіть шлях при імпорті.
            </div>
        @endif

        @if(! ($status['livewire_tmp_writable'] ?? true))
            <div class="db-error">
                <strong>Тимчасова папка Livewire недоступна для запису.</strong>
                Перевірте права на <code>storage/app/livewire-tmp</code>.
            </div>
        @endif

        @if(! ($status['dump_available'] ?? false))
            <div class="db-error">
                <strong>mysqldump недоступний.</strong>
                Локально: скопіюйте <code>docker/php/Dockerfile.example</code> → <code>Dockerfile</code> або встановіть mysql-client.
                На сервері: додайте <code>default-mysql-client</code> у свій Dockerfile і перезберіть PHP-контейнер.
            </div>
        @else
            <div class="db-warning">
                Імпорт — небезпечна операція. Завжди робіть експорт перед заміною бази.
                Після імпорту перейдіть у «Кеш каталогу» і прогрійте кеш.
            </div>

            <p style="margin-top: 16px; font-size: 13px; color: #64748b;">
                Експорт одразу завантажить файл <code>kubii-YYYYMMDD-HHMMSS.sql.gz</code> у папку «Завантаження» браузера.
                Якщо завантаження через браузер не працює — скопіюйте дамп у
                <code>storage/app/private/database-imports/</code> і вкажіть шлях у формі імпорту
                або виконайте <code>php artisan db:import storage/app/private/database-imports/файл.sql.gz</code>.
            </p>
        @endif
    </div>
</x-filament-panels::page>
