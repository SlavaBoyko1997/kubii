@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\CounterpartyFeedSyncLog> $feedSyncLogs */
    $feedSyncLogs = $feedSyncLogs ?? collect();
@endphp

<style>
    .feed-sync-log-panel {
        margin-bottom: 20px;
        padding: 20px 22px;
        border: 1px solid rgba(148, 163, 184, .25);
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 1px 4px rgba(15, 23, 42, .06);
    }

    .feed-sync-log-panel h3 {
        margin: 0 0 6px;
        font-size: 16px;
        font-weight: 700;
        color: #17211c;
    }

    .feed-sync-log-panel p {
        margin: 0 0 16px;
        color: #64748b;
        font-size: 13px;
    }

    .feed-sync-log-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .feed-sync-log-table th,
    .feed-sync-log-table td {
        padding: 10px 12px;
        border-bottom: 1px solid rgba(148, 163, 184, .18);
        text-align: left;
        vertical-align: top;
    }

    .feed-sync-log-table th {
        color: #64748b;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .feed-sync-log-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
    }

    .feed-sync-log-badge.is-auto {
        background: rgba(59, 130, 246, .12);
        color: #1d4ed8;
    }

    .feed-sync-log-badge.is-manual {
        background: rgba(49, 95, 69, .12);
        color: #315f45;
    }

    .feed-sync-log-badge.is-cli {
        background: rgba(100, 116, 139, .12);
        color: #475569;
    }

    .feed-sync-log-badge.is-success {
        background: rgba(34, 197, 94, .12);
        color: #15803d;
    }

    .feed-sync-log-badge.is-failed {
        background: rgba(220, 38, 38, .12);
        color: #b91c1c;
    }

    .feed-sync-log-empty {
        padding: 18px 0 4px;
        color: #64748b;
        font-size: 13px;
    }

    .feed-sync-log-error {
        color: #b91c1c;
        font-size: 12px;
        line-height: 1.45;
    }

    @media (max-width: 900px) {
        .feed-sync-log-table thead {
            display: none;
        }

        .feed-sync-log-table tr {
            display: block;
            padding: 10px 0;
            border-bottom: 1px solid rgba(148, 163, 184, .18);
        }

        .feed-sync-log-table td {
            display: block;
            border: 0;
            padding: 4px 0;
        }
    }
</style>

<div class="feed-sync-log-panel">
    <h3>Журнал оновлень фіду</h3>
    <p>Останні 30 синхронізацій: автоматичні (scheduler), ручні з адмінки та CLI.</p>

    @if($feedSyncLogs->isEmpty())
        <div class="feed-sync-log-empty">Ще не було жодного імпорту.</div>
    @else
        <table class="feed-sync-log-table">
            <thead>
                <tr>
                    <th>Час</th>
                    <th>Джерело</th>
                    <th>Статус</th>
                    <th>Результат</th>
                    <th>Тривалість</th>
                </tr>
            </thead>
            <tbody>
                @foreach($feedSyncLogs as $log)
                    <tr wire:key="feed-sync-log-{{ $log->id }}">
                        <td>
                            <strong>{{ $log->finished_at?->format('d.m.Y H:i') ?? '—' }}</strong>
                            @if($log->started_at)
                                <div style="color:#94a3b8;font-size:11px;margin-top:2px;">
                                    старт {{ $log->started_at->format('H:i:s') }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <span @class([
                                'feed-sync-log-badge',
                                'is-auto' => $log->trigger === 'auto',
                                'is-manual' => $log->trigger === 'manual',
                                'is-cli' => $log->trigger === 'cli',
                            ])>{{ $log->triggerLabel() }}</span>
                        </td>
                        <td>
                            <span @class([
                                'feed-sync-log-badge',
                                'is-success' => $log->status === 'success',
                                'is-failed' => $log->status === 'failed',
                            ])>{{ $log->statusLabel() }}</span>
                        </td>
                        <td>
                            @if($log->status === 'success')
                                {{ number_format((int) $log->products_count) }} товарів,
                                {{ number_format((int) $log->categories_count) }} кат.,
                                {{ number_format((int) $log->variant_groups_created) }} груп
                            @else
                                <span class="feed-sync-log-error">{{ $log->error_message }}</span>
                            @endif
                        </td>
                        <td>
                            @php($duration = $log->durationSeconds())
                            @if($duration !== null)
                                @if($duration < 60)
                                    {{ $duration }} с
                                @else
                                    {{ intdiv($duration, 60) }} хв {{ $duration % 60 }} с
                                @endif
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
