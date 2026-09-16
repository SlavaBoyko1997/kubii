@php
    $status = $this->imageMirrorStatus ?? [];
    $state = $status['state'] ?? 'idle';
    $isRunning = $state === 'running';
    $percent = (int) ($status['percent'] ?? 0);
    $current = (int) ($status['current'] ?? 0);
    $total = (int) ($status['total'] ?? 0);
@endphp

@if($isRunning || in_array($state, ['completed', 'failed', 'cancelled'], true))
    <style>
        .image-mirror-panel {
            margin-bottom: 20px;
            padding: 20px 22px;
            border: 1px solid rgba(148, 163, 184, .25);
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 1px 4px rgba(15, 23, 42, .06);
        }

        .image-mirror-panel.is-running {
            border-color: rgba(37, 99, 235, .35);
        }

        .image-mirror-panel.is-failed {
            border-color: rgba(220, 38, 38, .35);
        }

        .image-mirror-panel.is-cancelled {
            border-color: rgba(245, 158, 11, .4);
        }

        .image-mirror-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }

        .image-mirror-head h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: #17211c;
        }

        .image-mirror-head p {
            margin: 6px 0 0;
            color: #64748b;
            font-size: 13px;
        }

        .image-mirror-badge {
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .image-mirror-badge.is-running {
            background: rgba(37, 99, 235, .12);
            color: #1d4ed8;
        }

        .image-mirror-badge.is-completed {
            background: rgba(34, 197, 94, .12);
            color: #15803d;
        }

        .image-mirror-badge.is-failed {
            background: rgba(220, 38, 38, .12);
            color: #b91c1c;
        }

        .image-mirror-badge.is-cancelled {
            background: rgba(245, 158, 11, .14);
            color: #92400e;
        }

        .image-mirror-meta {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 650;
            color: #334155;
        }

        .image-mirror-bar {
            overflow: hidden;
            height: 14px;
            border-radius: 999px;
            background: #e7eef8;
        }

        .image-mirror-bar > span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #2563eb, #60a5fa);
            transition: width .35s ease;
        }

        .image-mirror-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .image-mirror-stats small {
            display: block;
            color: #64748b;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .image-mirror-stats strong {
            display: block;
            margin-top: 4px;
            color: #17211c;
            font-size: 14px;
        }

        .image-mirror-phase-summary {
            margin-top: 14px;
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(34, 197, 94, .08);
            color: #166534;
            font-size: 13px;
        }

        .image-mirror-error {
            margin-top: 14px;
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(220, 38, 38, .08);
            color: #b91c1c;
            font-size: 13px;
        }

        .image-mirror-error.is-cancelled {
            background: rgba(245, 158, 11, .1);
            color: #92400e;
        }

        .image-mirror-failures {
            margin-top: 14px;
            padding: 12px 14px;
            border-radius: 10px;
            background: rgba(245, 158, 11, .08);
            color: #92400e;
            font-size: 13px;
        }

        .image-mirror-failures h4 {
            margin: 0 0 8px;
            font-size: 13px;
            font-weight: 700;
            color: #78350f;
        }

        .image-mirror-failures ul {
            margin: 0;
            padding-left: 18px;
        }

        .image-mirror-failures li {
            margin-bottom: 4px;
        }

        .image-mirror-failures small {
            display: block;
            margin-top: 8px;
            color: #a16207;
            word-break: break-all;
        }

        @media (max-width: 768px) {
            .image-mirror-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div
        class="image-mirror-panel @if($isRunning) is-running @elseif($state === 'failed') is-failed @elseif($state === 'cancelled') is-cancelled @endif"
        @if($isRunning || (($status['state'] ?? '') === 'failed' && str_contains((string) ($status['message'] ?? ''), 'продовжуємо'))) wire:poll.2s="refreshFeedImportProgress" @endif
    >
        <div class="image-mirror-head">
            <div>
                <h3>Завантаження фото</h3>
                <p>{{ $status['stage'] ?? 'Очікування' }}</p>
            </div>
            <span @class([
                'image-mirror-badge',
                'is-running' => $isRunning,
                'is-completed' => $state === 'completed',
                'is-failed' => $state === 'failed',
                'is-cancelled' => $state === 'cancelled',
            ])>
                @switch($state)
                    @case('running') Виконується @break
                    @case('completed') Готово @break
                    @case('failed') Помилка @break
                    @case('cancelled') Зупинено @break
                    @default —
                @endswitch
            </span>
        </div>

        <div class="image-mirror-meta">
            <span>
                @if($total > 0)
                    @if(($status['phase'] ?? 'main') === 'gallery')
                        {{ number_format($current) }} з {{ number_format($total) }} фото галереї
                    @else
                        {{ number_format($current) }} з {{ number_format($total) }} товарів
                    @endif
                @else
                    Прогрес
                @endif
            </span>
            <strong>{{ $percent }}%</strong>
        </div>

        <div class="image-mirror-bar" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
            <span style="width: {{ $percent }}%"></span>
        </div>

        @if(($status['phase'] ?? 'main') === 'gallery' && array_key_exists('main_mirrored', $status))
            <div class="image-mirror-phase-summary">
                Головні фото завершено:
                збережено {{ number_format((int) ($status['main_mirrored'] ?? 0)) }},
                не вдалося {{ number_format((int) ($status['main_failed'] ?? 0)) }},
                оброблено {{ number_format((int) ($status['main_processed'] ?? 0)) }}
                з {{ number_format((int) ($status['main_total'] ?? 0)) }} товарів.
            </div>
        @endif

        <div class="image-mirror-stats">
            <div>
                <small>Збережено</small>
                <strong>{{ number_format((int) ($status['mirrored'] ?? 0)) }}</strong>
            </div>
            <div>
                <small>Не вдалось</small>
                <strong>{{ number_format((int) ($status['failed'] ?? 0)) }}</strong>
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
            <div @class(['image-mirror-error', 'is-cancelled' => $state === 'cancelled'])>{{ $status['message'] }}</div>
        @endif

        @if(! empty($status['failure_reasons']))
            <div class="image-mirror-failures">
                <h4>Причини помилок завантаження</h4>
                <ul>
                    @foreach($status['failure_reasons'] as $reason => $count)
                        <li>{{ \App\Support\ProductImageMirrorFailure::label((string) $reason) }} — {{ number_format((int) $count) }}</li>
                    @endforeach
                </ul>
                @if(! empty($status['failure_samples']))
                    <small>
                        @foreach(array_slice($status['failure_samples'], 0, 5) as $sample)
                            {{ \App\Support\ProductImageMirrorFailure::label((string) ($sample['reason'] ?? '')) }}:
                            {{ \Illuminate\Support\Str::limit((string) (($sample['request_url'] ?? '') !== '' ? $sample['request_url'] : ($sample['url'] ?? '')), 160) }}
                            @if(! empty($sample['detail']) && ($sample['detail'] ?? '') !== ($sample['request_url'] ?? ''))
                                ({{ $sample['detail'] }})
                            @endif
                            <br>
                        @endforeach
                    </small>
                @endif
            </div>
        @endif
    </div>
@endif
