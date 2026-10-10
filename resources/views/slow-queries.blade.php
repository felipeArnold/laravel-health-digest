<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Slow queries · Health Digest</title>
    <link rel="icon" href="data:,">
    <style>
        :root {
            --background: #f6f7f9;
            --surface: #ffffff;
            --border: #e3e6ea;
            --text: #1d2330;
            --muted: #667085;
            --accent: #3b5bdb;
            --code-background: #f1f3f6;
            --warning: #b54708;
            --warning-background: #fef0c7;
            --danger: #b42318;
            --danger-background: #fee4e2;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --background: #0f1218;
                --surface: #171b23;
                --border: #2a303c;
                --text: #e6e9ef;
                --muted: #98a2b3;
                --accent: #7c95f5;
                --code-background: #10141b;
                --warning: #fdb022;
                --warning-background: #3d2a07;
                --danger: #fda29b;
                --danger-background: #43140f;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font: 14px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        main { max-width: 1280px; margin: 0 auto; padding: 32px 16px 48px; }

        h1 { margin: 0; font-size: 22px; font-weight: 650; }

        .subtitle { margin: 4px 0 24px; color: var(--muted); }

        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 20px; }

        .stat, .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; }

        .stat { padding: 14px 16px; }

        .stat-label { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }

        .stat-value { margin-top: 2px; font-size: 22px; font-weight: 650; font-variant-numeric: tabular-nums; }

        .filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; padding: 14px 16px; margin-bottom: 20px; }

        .filters label { display: grid; gap: 4px; color: var(--muted); font-size: 12px; }

        .filters .search { flex: 1 1 240px; }

        input, select, button {
            height: 36px;
            padding: 0 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface);
            color: var(--text);
            font: inherit;
        }

        input { width: 100%; }

        button { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; cursor: pointer; padding: 0 16px; }

        .clear { align-self: center; color: var(--muted); }

        .table-wrapper { overflow-x: auto; }

        table { width: 100%; border-collapse: collapse; }

        th, td { padding: 12px 16px; text-align: left; vertical-align: top; border-bottom: 1px solid var(--border); }

        tbody tr:last-child td { border-bottom: 0; }

        th { color: var(--muted); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; white-space: nowrap; }

        .number { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }

        .rank { color: var(--muted); width: 1%; }

        .query { min-width: 360px; }

        code {
            display: block;
            padding: 8px 10px;
            border-radius: 6px;
            background: var(--code-background);
            font: 12.5px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            white-space: pre-wrap;
            word-break: break-word;
        }

        details { margin-top: 8px; color: var(--muted); font-size: 12.5px; }

        summary { cursor: pointer; }

        dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 12px; margin: 8px 0 0; }

        dt { font-weight: 600; }

        dd { margin: 0; word-break: break-word; }

        .badge { display: inline-block; padding: 1px 8px; border-radius: 999px; font-weight: 600; }

        .badge-warning { background: var(--warning-background); color: var(--warning); }

        .badge-danger { background: var(--danger-background); color: var(--danger); }

        .tenants { max-width: 220px; color: var(--muted); }

        .empty { padding: 48px 16px; text-align: center; color: var(--muted); }

        a { color: var(--accent); }

        @media (max-width: 720px) {
            thead { display: none; }

            table, tbody, tr, td { display: block; }

            tr { padding: 12px 16px; border-bottom: 1px solid var(--border); }

            tbody tr:last-child { border-bottom: 0; }

            td { padding: 4px 0; border: 0; text-align: left; }

            td[data-label] { display: flex; justify-content: space-between; gap: 12px; }

            td[data-label]::before { content: attr(data-label); color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }

            .rank { display: none; }

            .query { min-width: 0; padding-bottom: 8px; }

            .tenants { max-width: none; }
        }
    </style>
</head>
<body>
<main>
    <h1>Slow queries</h1>
    <p class="subtitle">
        Queries above {{ number_format((int) config('health-digest.query_threshold_ms')) }} ms in the last {{ $hours }} {{ $hours === 1 ? 'hour' : 'hours' }}, grouped by SQL without literals and ranked by count × p95.
    </p>

    <section class="stats">
        <div class="stat">
            <div class="stat-label">Distinct queries</div>
            <div class="stat-value">{{ number_format(count($queries)) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Occurrences</div>
            <div class="stat-value">{{ number_format($occurrences) }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Slowest</div>
            <div class="stat-value">{{ number_format($slowestMilliseconds) }} ms</div>
        </div>
    </section>

    <form method="get" class="panel filters">
        <label>
            Period
            <select name="hours">
                @foreach ($hourOptions as $hourOption)
                    <option value="{{ $hourOption }}" @selected($hourOption === $hours)>
                        {{ $hourOption < 24 ? $hourOption.'h' : ($hourOption / 24).'d' }}
                    </option>
                @endforeach
            </select>
        </label>

        @if ($tenants !== [] || $tenant !== '')
            <label>
                Tenant
                <select name="tenant">
                    <option value="">All</option>
                    @foreach ($tenants as $tenantOption)
                        <option value="{{ $tenantOption }}" @selected((string) $tenantOption === $tenant)>{{ $tenantOption }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <label class="search">
            Search SQL
            <input type="search" name="search" value="{{ $search }}" placeholder="table, column, keyword">
        </label>

        <button type="submit">Filter</button>

        @if ($tenant !== '' || $search !== '')
            <a class="clear" href="?hours={{ $hours }}">Clear</a>
        @endif
    </form>

    <section class="panel">
        @if ($queries === [])
            <div class="empty">No slow queries in this period.</div>
        @else
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th class="rank">#</th>
                            <th>Query</th>
                            <th class="number">Count</th>
                            <th class="number">p95</th>
                            <th class="number">Max</th>
                            <th>Tenants</th>
                            <th>Last seen</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($queries as $query)
                            <tr>
                                <td class="rank">{{ $loop->iteration }}</td>
                                <td class="query">
                                    <code>{{ $query['sample'] }}</code>
                                    <details>
                                        <summary>Details</summary>
                                        <dl>
                                            <dt>Origins</dt>
                                            <dd>{{ implode(', ', $query['origins']) ?: '—' }}</dd>
                                            <dt>First seen</dt>
                                            <dd>{{ $query['first_seen'] }}</dd>
                                            <dt>Fingerprint</dt>
                                            <dd>{{ $query['fingerprint'] }}</dd>
                                        </dl>
                                    </details>
                                </td>
                                <td class="number" data-label="Count">{{ number_format($query['count']) }}</td>
                                <td class="number" data-label="p95">
                                    <span @class(['badge', 'badge-danger' => ($query['p95_ms'] ?? 0) >= 2000, 'badge-warning' => ($query['p95_ms'] ?? 0) < 2000])>
                                        {{ number_format($query['p95_ms'] ?? 0) }} ms
                                    </span>
                                </td>
                                <td class="number" data-label="Max">{{ number_format($query['max_ms'] ?? 0) }} ms</td>
                                <td class="tenants" data-label="Tenants">{{ implode(', ', $query['tenants']) ?: '—' }}</td>
                                <td class="number" data-label="Last seen">{{ $query['last_seen'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if (count($queries) === $limit)
                <div class="empty">Showing the top {{ $limit }} queries. Narrow the period or filter to see others.</div>
            @endif
        @endif
    </section>
</main>
</body>
</html>
