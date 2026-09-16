@php
    $status = $this->feedImportStatus ?? [];
    $state = $status['state'] ?? 'idle';
    $isRunning = in_array($state, ['queued', 'running'], true);
    $percent = (int) ($status['percent'] ?? 0);
    $current = (int) ($status['current'] ?? 0);
    $total = (int) ($status['total'] ?? 0);
@endphp

@if($isRunning || in_array($state, ['completed', 'failed'], true))
    <style>
        .feed-import-panel {
            margin-bottom: 20px;
            padding: 20px 22px;
            border: 1px solid rgba(148, 163, 184, .25);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 1px 4px rgba(15, 23, 42, .06);
        }

        .feed-import-panel.is-running {
            border-color: rgba(49, 95, 69, .35);
        }

        .feed-import-panel.is-failed {
            border-color: rgba(220, 38, 38, .35);
        }

        .feed-import-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }

        .feed-import-head h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: #17211c;
        }

        .feed-import-head p {
            margin: 6px 0 0;
            color: #64748b;
            font-size: 13px;
        }

        .feed-import-badge {
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .feed-import-badge.is-queued,
        .feed-import-badge.is-running {
            background: rgba(49, 95, 69, .12);
            color: #315f45;
        }

        .feed-import-badge.is-completed {
            background: rgba(34, 197, 94, .12);
            color: #15803d;
        }

        .feed-import-badge.is-failed {
            background: rgba(220, 38, 38, .12);
            color: #b91c1c;
        }

        .feed-import-meta {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 650;
            color: #334155;
        }

        .feed-import-bar {
            overflow: hidden;
            height: 14px;
            border-radius: 999px;
            background: #e7ede9;
        }

        .feed-import-bar > span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #315f45, #6f9b78);
            transition: width .35s ease;
        }

        .feed-import-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .feed-import-stats small {
            display: block;
            color: #64748b;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .feed-import-stats strong {
            display: block;
            margin-top: 4px;
            color: #17211c;
            font-size: 14px;
        }

        .feed-import-error {
            margin-top: 14px;
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(220, 38, 38, .08);
            color: #b91c1c;
            font-size: 13px;
        }

        @media (max-width: 768px) {
            .feed-import-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div
        class="feed-import-panel @if($isRunning) is-running @elseif($state === 'failed') is-failed @endif"
        @if($isRunning) wire:poll.2s="refreshFeedImportProgress" @elseif(($this->imageMirrorStatus['state'] ?? 'idle') === 'running') wire:poll.2s="refreshFeedImportProgress" @endif
    >
        <div class="feed-import-head">
            <div>
                <h3>Обробка фіду</h3>
                <p>{{ $status['stage'] ?? 'Очікування' }}</p>
            </div>
            <span @class([
                'feed-import-badge',
                'is-queued' => $state === 'queued',
                'is-running' => $state === 'running',
                'is-completed' => $state === 'completed',
                'is-failed' => $state === 'failed',
            ])>
                @switch($state)
                    @case('queued') У черзі @break
                    @case('running') Виконується @break
                    @case('completed') Готово @break
                    @case('failed') Помилка @break
                    @default —
                @endswitch
            </span>
        </div>

        <div class="feed-import-meta">
            <span>
                @if(($status['stage'] ?? '') === 'Імпорт товарів' && $total > 0)
                    {{ number_format($current) }} з {{ number_format($total) }} товарів
                @elseif(($status['products_imported'] ?? 0) > 0)
                    {{ number_format((int) $status['products_imported']) }} товарів
                @else
                    Прогрес
                @endif
            </span>
            <strong>{{ $percent }}%</strong>
        </div>

        <div class="feed-import-bar" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
            <span style="width: {{ $percent }}%"></span>
        </div>

        <div class="feed-import-stats">
            <div>
                <small>Категорій</small>
                <strong>{{ isset($status['categories_count']) ? number_format((int) $status['categories_count']) : '—' }}</strong>
            </div>
            <div>
                <small>Груп варіантів</small>
                <strong>{{ isset($status['variant_groups_created']) ? number_format((int) $status['variant_groups_created']) : '—' }}</strong>
            </div>
            <div>
                <small>Завершено</small>
                <strong>
                    @if(! empty($status['finished_at']))
                        {{ \Illuminate\Support\Carbon::parse($status['finished_at'])->format('d.m.Y H:i') }}
                    @else
                        —
                    @endif
                </strong>
            </div>
        </div>

        @if(! empty($status['message']))
            <div class="feed-import-error">{{ $status['message'] }}</div>
        @endif
    </div>
@endif
