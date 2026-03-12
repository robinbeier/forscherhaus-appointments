import type {OrchestratorSnapshot} from './orchestrator.js';

function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function formatInteger(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return 'n/a';
    }

    return new Intl.NumberFormat('en-US').format(Math.floor(value));
}

function formatDecimal(value: number | null | undefined, maximumFractionDigits = 1): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return 'n/a';
    }

    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 0,
        maximumFractionDigits,
    }).format(value);
}

function formatPercent(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return 'n/a';
    }

    return `${formatDecimal(value)}%`;
}

function formatDuration(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined || !Number.isFinite(seconds)) {
        return 'n/a';
    }

    const totalSeconds = Math.max(0, Math.floor(seconds));
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const remainingSeconds = totalSeconds % 60;

    if (hours > 0) {
        return `${hours}h ${minutes}m ${remainingSeconds}s`;
    }

    if (minutes > 0) {
        return `${minutes}m ${remainingSeconds}s`;
    }

    return `${remainingSeconds}s`;
}

function formatTimestamp(value: string | undefined): string {
    if (!value) {
        return 'Never';
    }

    const parsed = Date.parse(value);
    if (Number.isNaN(parsed)) {
        return value;
    }

    return new Date(parsed).toISOString().replace('.000Z', 'Z');
}

function formatRateLimitValue(value: unknown): string {
    if (typeof value === 'number') {
        return formatDecimal(value, 2);
    }

    if (typeof value === 'string' || typeof value === 'boolean') {
        return String(value);
    }

    if (value === null || value === undefined) {
        return 'n/a';
    }

    return JSON.stringify(value);
}

function renderMetricCard(label: string, value: string, hint: string): string {
    return `<article class="metric-card"><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd><p>${escapeHtml(hint)}</p></article>`;
}

function renderHealthIndicator(indicator: OrchestratorSnapshot['health']['indicators'][number]): string {
    return `<li class="health-item health-item--${escapeHtml(indicator.status)}">
        <span class="pill pill--${escapeHtml(indicator.status)}">${escapeHtml(indicator.status)}</span>
        <div>
            <strong>${escapeHtml(indicator.code)}</strong>
            <p>${escapeHtml(indicator.message)}</p>
        </div>
    </li>`;
}

function renderRunningEntry(entry: OrchestratorSnapshot['running'][number]): string {
    const traceItems =
        entry.trace_tail.length > 0
            ? `<ul class="trace-list">${entry.trace_tail
                  .map(
                      (trace) =>
                          `<li><strong>${escapeHtml(trace.eventType)}</strong> <span>${escapeHtml(trace.message)}</span></li>`,
                  )
                  .join('')}</ul>`
            : '<p class="muted">No recent trace entries.</p>';

    return `<article class="entry-card">
        <header class="entry-card__header">
            <div>
                <h3><a href="/api/v1/${encodeURIComponent(entry.issue_identifier)}">${escapeHtml(entry.issue_identifier)}</a></h3>
                <p>${escapeHtml(entry.last_activity ?? 'No recent activity message.')}</p>
            </div>
            <span class="pill">${escapeHtml(entry.source)}</span>
        </header>
        <dl class="entry-grid">
            <div><dt>Runtime</dt><dd>${escapeHtml(formatDuration(entry.runtime_seconds))}</dd></div>
            <div><dt>Idle</dt><dd>${escapeHtml(formatDuration(entry.idle_seconds))}</dd></div>
            <div><dt>Total tokens</dt><dd>${escapeHtml(formatInteger(entry.total_tokens))}</dd></div>
            <div><dt>Last turn</dt><dd>${escapeHtml(formatInteger(entry.last_turn_tokens))}</dd></div>
            <div><dt>Context headroom</dt><dd>${escapeHtml(formatInteger(entry.context_headroom_tokens))}</dd></div>
            <div><dt>Utilization</dt><dd>${escapeHtml(formatPercent(entry.context_utilization_percent))}</dd></div>
            <div><dt>Last activity</dt><dd>${escapeHtml(formatTimestamp(entry.last_activity_at))}</dd></div>
            <div><dt>Session</dt><dd>${escapeHtml(entry.session_id ?? 'n/a')}</dd></div>
        </dl>
        <section>
            <h4>Recent trace</h4>
            ${traceItems}
        </section>
    </article>`;
}

function renderRetryEntry(entry: OrchestratorSnapshot['retrying'][number]): string {
    return `<tr>
        <td><a href="/api/v1/${encodeURIComponent(entry.issue_identifier)}">${escapeHtml(entry.issue_identifier)}</a></td>
        <td>${escapeHtml(String(entry.attempt))}</td>
        <td>${escapeHtml(entry.reason)}</td>
        <td>${escapeHtml(formatTimestamp(entry.available_at))}</td>
        <td>${escapeHtml(entry.error_class ?? 'n/a')}</td>
    </tr>`;
}

function renderRecentEvent(event: OrchestratorSnapshot['recent_events'][number]): string {
    return `<li class="event-item">
        <div class="event-item__meta">
            <a href="/api/v1/${encodeURIComponent(event.issue_identifier)}">${escapeHtml(event.issue_identifier)}</a>
            <span>${escapeHtml(formatTimestamp(event.atIso))}</span>
        </div>
        <p><strong>${escapeHtml(event.eventType)}</strong> ${escapeHtml(event.message)}</p>
    </li>`;
}

export function renderDashboard(snapshot: OrchestratorSnapshot): string {
    const rateLimitItems = Object.entries(snapshot.rate_limits);
    const healthMarkup = `<ul class="health-list">${snapshot.health.indicators
        .map((indicator) => renderHealthIndicator(indicator))
        .join('')}</ul>`;
    const runningMarkup =
        snapshot.running.length > 0
            ? snapshot.running.map((entry) => renderRunningEntry(entry)).join('')
            : '<p class="empty-state">No issues are currently running.</p>';
    const retryMarkup =
        snapshot.retrying.length > 0
            ? `<table class="retry-table"><thead><tr><th>Issue</th><th>Attempt</th><th>Reason</th><th>Retry at</th><th>Error</th></tr></thead><tbody>${snapshot.retrying
                  .map((entry) => renderRetryEntry(entry))
                  .join('')}</tbody></table>`
            : '<p class="empty-state">No retry queue entries.</p>';
    const rateLimitMarkup =
        rateLimitItems.length > 0
            ? `<dl class="rate-limit-list">${rateLimitItems
                  .map(
                      ([key, value]) =>
                          `<div><dt>${escapeHtml(key)}</dt><dd>${escapeHtml(formatRateLimitValue(value))}</dd></div>`,
                  )
                  .join('')}</dl>`
            : '<p class="empty-state">No rate-limit data reported.</p>';
    const recentEventMarkup =
        snapshot.recent_events.length > 0
            ? `<ol class="event-list">${snapshot.recent_events.map((event) => renderRecentEvent(event)).join('')}</ol>`
            : '<p class="empty-state">No recent events captured yet.</p>';

    return `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="15">
    <title>Symphony Dashboard</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f3efe6;
            --panel: #fffdf8;
            --ink: #1f1a17;
            --muted: #6e6258;
            --accent: #0f766e;
            --accent-soft: #d7f3ee;
            --border: #d8cec2;
            --warn: #92400e;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Iowan Old Style", "Palatino Linotype", "Book Antiqua", serif;
            background: radial-gradient(circle at top, #fff9ef 0%, var(--bg) 58%);
            color: var(--ink);
        }
        main {
            max-width: 1200px;
            margin: 0 auto;
            padding: 32px 20px 56px;
        }
        .hero, .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(49, 36, 25, 0.08);
        }
        .hero {
            padding: 28px;
            margin-bottom: 20px;
        }
        .hero__top, .section-header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        h1, h2, h3, h4, p {
            margin: 0;
        }
        h1 { font-size: clamp(2rem, 4vw, 3.3rem); }
        h2 { font-size: 1.5rem; margin-bottom: 14px; }
        h3 { font-size: 1.2rem; }
        h4 { font-size: 1rem; margin-bottom: 8px; }
        .muted, .hero p, .entry-card__header p, .metric-card p, th {
            color: var(--muted);
        }
        .hero p { margin-top: 8px; max-width: 65ch; }
        .hero-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }
        .hero-links a {
            color: var(--accent);
            text-decoration: none;
            border-bottom: 1px solid rgba(15, 118, 110, 0.35);
        }
        .meta-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-top: 22px;
        }
        .metric-grid, .running-grid, .secondary-grid {
            display: grid;
            gap: 16px;
        }
        .metric-grid {
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            margin: 20px 0 0;
        }
        .running-grid {
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        }
        .secondary-grid {
            grid-template-columns: 1.2fr 0.8fr;
        }
        .panel {
            padding: 22px;
        }
        .stack {
            display: grid;
            gap: 20px;
        }
        .metric-card, .entry-card, .meta-card {
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.95), rgba(247, 242, 236, 0.92));
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px;
        }
        .metric-card dt, .entry-grid dt, .rate-limit-list dt, .meta-card dt {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
        }
        .metric-card dd, .entry-grid dd, .rate-limit-list dd, .meta-card dd {
            margin: 8px 0 0;
            font-size: 1.45rem;
            font-weight: 700;
        }
        .metric-card p {
            margin-top: 10px;
            font-size: 0.9rem;
        }
        .entry-card__header {
            margin-bottom: 14px;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            padding: 5px 10px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent);
            font: 600 0.82rem/1.1 system-ui, sans-serif;
            text-transform: capitalize;
        }
        .pill--warning {
            background: #fff1c2;
            color: #8a5800;
        }
        .pill--error {
            background: #ffe2df;
            color: #9f1d18;
        }
        .entry-grid, .rate-limit-list, .meta-card dl {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 12px;
        }
        .health-list, .event-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .health-item, .event-item {
            display: grid;
            gap: 10px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }
        .health-item {
            grid-template-columns: auto 1fr;
            align-items: start;
        }
        .event-item__meta {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            color: var(--muted);
            font-size: 0.92rem;
        }
        .trace-list {
            margin: 0;
            padding-left: 18px;
        }
        .trace-list li + li {
            margin-top: 6px;
        }
        .retry-table {
            width: 100%;
            border-collapse: collapse;
        }
        .retry-table th, .retry-table td {
            text-align: left;
            padding: 10px 8px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }
        a {
            color: var(--ink);
        }
        .empty-state {
            padding: 18px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.55);
            border: 1px dashed var(--border);
            color: var(--muted);
        }
        .footnote {
            margin-top: 18px;
            color: var(--warn);
            font-size: 0.92rem;
        }
        @media (max-width: 900px) {
            .secondary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <main>
        <section class="hero">
            <div class="hero__top">
                <div>
                    <h1>Symphony Dashboard</h1>
                    <p>Operator-facing status surface for live pilot runs. This page auto-refreshes every 15 seconds and stays independent from orchestration correctness.</p>
                </div>
                <div class="pill">Generated ${escapeHtml(formatTimestamp(snapshot.generated_at))}</div>
            </div>
            <div class="hero-links">
                <a href="/api/v1/state">Snapshot JSON</a>
            </div>
            <div class="meta-strip">
                <section class="meta-card">
                    <dl>
                        <div><dt>Last tick</dt><dd>${escapeHtml(formatTimestamp(snapshot.last_tick_at))}</dd></div>
                        <div><dt>Running</dt><dd>${escapeHtml(formatInteger(snapshot.counts.running))}</dd></div>
                        <div><dt>Retrying</dt><dd>${escapeHtml(formatInteger(snapshot.counts.retrying))}</dd></div>
                    </dl>
                </section>
                <section class="meta-card">
                    <dl>
                        <div><dt>Completed</dt><dd>${escapeHtml(formatInteger(snapshot.counts.completed))}</dd></div>
                        <div><dt>Input required</dt><dd>${escapeHtml(formatInteger(snapshot.counts.input_required))}</dd></div>
                        <div><dt>Failed</dt><dd>${escapeHtml(formatInteger(snapshot.counts.failed))}</dd></div>
                    </dl>
                </section>
            </div>
            <div class="metric-grid">
                ${renderMetricCard('Runtime total', formatDuration(snapshot.totals.runtime_seconds), 'Aggregate runtime across active entries.')}
                ${renderMetricCard('Input tokens', formatInteger(snapshot.totals.input_tokens), 'Prompt and context consumption observed so far.')}
                ${renderMetricCard('Output tokens', formatInteger(snapshot.totals.output_tokens), 'Model output produced across active entries.')}
                ${renderMetricCard('Total tokens', formatInteger(snapshot.totals.total_tokens), 'Combined live token spend across active entries.')}
            </div>
        </section>
        <div class="stack">
            <div class="secondary-grid">
                <section class="panel">
                    <div class="section-header">
                        <h2>Health</h2>
                        <p class="muted">Overall ${escapeHtml(snapshot.health.overall)}</p>
                    </div>
                    ${healthMarkup}
                </section>
                <section class="panel">
                    <div class="section-header">
                        <h2>Recent Events</h2>
                        <p class="muted">${escapeHtml(formatInteger(snapshot.recent_events.length))} newest entries</p>
                    </div>
                    ${recentEventMarkup}
                </section>
            </div>
            <section class="panel">
                <div class="section-header">
                    <h2>Running Issues</h2>
                    <p class="muted">${escapeHtml(formatInteger(snapshot.running.length))} active</p>
                </div>
                <div class="running-grid">${runningMarkup}</div>
            </section>
            <div class="secondary-grid">
                <section class="panel">
                    <div class="section-header">
                        <h2>Retry Queue</h2>
                        <p class="muted">${escapeHtml(formatInteger(snapshot.retrying.length))} waiting</p>
                    </div>
                    ${retryMarkup}
                </section>
                <section class="panel">
                    <div class="section-header">
                        <h2>Rate Limits</h2>
                        <p class="muted">Raw tracker from the last snapshot</p>
                    </div>
                    ${rateLimitMarkup}
                    <p class="footnote">Use the JSON API for automation. The dashboard is intentionally read-only and best-effort.</p>
                </section>
            </div>
        </div>
    </main>
</body>
</html>`;
}
