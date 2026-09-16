<x-filament-panels::page>
    <style>
        .cache-panel {
            max-width: 820px;
            padding: 24px;
            border: 1px solid rgba(148, 163, 184, .25);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 1px 4px rgba(15, 23, 42, .06);
        }

        .cache-panel-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
        }

        .cache-panel h2 {
            margin: 0;
            color: #17211c;
            font-size: 20px;
            font-weight: 750;
        }

        .cache-panel p {
            margin: 7px 0 0;
            color: #64748b;
            font-size: 14px;
        }

        .cache-button {
            min-height: 42px;
            padding: 10px 16px;
            border: 0;
            border-radius: 10px;
            background: #315f45;
            color: #fff;
            cursor: pointer;
            font-weight: 700;
            white-space: nowrap;
        }

        .cache-button:disabled {
            cursor: wait;
            opacity: .55;
        }

        .cache-progress-wrap {
            margin-top: 28px;
        }

        .cache-progress-meta {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 9px;
            color: #334155;
            font-size: 13px;
            font-weight: 650;
        }

        .cache-progress {
            overflow: hidden;
            height: 16px;
            border-radius: 999px;
            background: #e7ede9;
        }

        .cache-progress-value {
            height: 100%;
            min-width: 0;
            border-radius: inherit;
            background: linear-gradient(90deg, #315f45, #6f9b78);
            transition: width .35s ease;
        }

        .cache-state {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 18px;
        }

        .cache-state div {
            padding: 12px;
            border-radius: 11px;
            background: #f8fafc;
        }

        .cache-state small,
        .cache-state strong {
            display: block;
        }

        .cache-state small {
            color: #64748b;
            font-size: 11px;
        }

        .cache-state strong {
            margin-top: 4px;
            color: #17211c;
            font-size: 13px;
        }

        .cache-error {
            margin-top: 16px;
            padding: 12px 14px;
            border-radius: 10px;
            background: #fef2f2;
            color: #991b1b;
            font-size: 13px;
        }

        @media (prefers-color-scheme: dark) {
            .cache-panel {
                border-color: rgba(255, 255, 255, .1);
                background: #111827;
            }

            .cache-panel h2,
            .cache-progress-meta,
            .cache-state strong {
                color: #f8fafc;
            }

            .cache-panel p,
            .cache-state small {
                color: #94a3b8;
            }

            .cache-progress,
            .cache-state div {
                background: rgba(255, 255, 255, .07);
            }
        }

        @media (max-width: 680px) {
            .cache-panel-head {
                display: grid;
            }

            .cache-state {
                grid-template-columns: 1fr;
            }
        }
    </style>

    @php($isRunning = in_array($status['state'] ?? null, ['queued', 'running'], true))

    <div class="cache-panel" @if($isRunning) wire:poll.2s="refreshProgress" @endif>
        <div class="cache-panel-head">
            <div>
                <h2>Перегенерація Redis-кешу</h2>
                <p>Нова версія створюється окремо. Поточний кеш працює до повного завершення оновлення.</p>
            </div>

            <button
                type="button"
                class="cache-button"
                wire:click="startWarm"
                wire:loading.attr="disabled"
                wire:target="startWarm"
                @disabled($isRunning)
            >
                {{ $isRunning ? 'Оновлення виконується…' : 'Перегенерувати кеш' }}
            </button>
        </div>

        <div class="cache-progress-wrap">
            <div class="cache-progress-meta">
                <span>{{ $status['stage'] ?? 'Очікування' }}</span>
                <strong>{{ (int) ($status['percent'] ?? 0) }}%</strong>
            </div>
            <div class="cache-progress" role="progressbar" aria-valuenow="{{ (int) ($status['percent'] ?? 0) }}" aria-valuemin="0" aria-valuemax="100">
                <div class="cache-progress-value" style="width: {{ (int) ($status['percent'] ?? 0) }}%"></div>
            </div>
        </div>

        <div class="cache-state">
            <div>
                <small>Стан</small>
                <strong>
                    @switch($status['state'] ?? 'idle')
                        @case('queued') У черзі @break
                        @case('running') Генерується @break
                        @case('completed') Готово @break
                        @case('failed') Помилка @break
                        @default Не запускався
                    @endswitch
                </strong>
            </div>
            <div>
                <small>Виконано</small>
                <strong>{{ (int) ($status['current'] ?? 0) }} із {{ (int) ($status['total'] ?? 0) }} кроків</strong>
            </div>
            <div>
                <small>Завершено</small>
                <strong>{{ !empty($status['finished_at']) ? \Illuminate\Support\Carbon::parse($status['finished_at'])->format('d.m.Y H:i') : '—' }}</strong>
            </div>
        </div>

        @if(!empty($status['message']))
            <div class="cache-error">{{ $status['message'] }}</div>
        @endif
    </div>

    @include('filament.pages.catalog-cache-log', ['cacheLogs' => $cacheLogs ?? collect()])
</x-filament-panels::page>
