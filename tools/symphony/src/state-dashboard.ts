import type {OrchestratorSnapshot} from './orchestrator.js';
import {
    presentStateSnapshot,
    type PresentedHealthIndicator,
    type PresentedRecentEvent,
    type PresentedRetryEntry,
    type PresentedRunningEntry,
} from './state-presenter.js';

function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function renderMetricCard(label: string, value: string, hint: string): string {
    return `<article class="metric-card"><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd><p>${escapeHtml(hint)}</p></article>`;
}

function renderHealthIndicator(indicator: PresentedHealthIndicator): string {
    return `<li class="health-item health-item--${escapeHtml(indicator.status)}">
        <span class="pill pill--${escapeHtml(indicator.status)}">${escapeHtml(indicator.status)}</span>
        <div>
            <strong>${escapeHtml(indicator.code)}</strong>
            <p>${escapeHtml(indicator.message)}</p>
        </div>
    </li>`;
}

function renderRunningEntry(entry: PresentedRunningEntry): string {
    const traceItems =
        entry.traceTail.length > 0
            ? `<ul class="trace-list">${entry.traceTail
                  .map(
                      (trace) =>
                          `<li><strong>${escapeHtml(trace.eventType)}</strong> <span>${escapeHtml(trace.message)}</span></li>`,
                  )
                  .join('')}</ul>`
            : '<p class="muted">No recent trace entries.</p>';

    return `<article class="entry-card">
        <header class="entry-card__header">
            <div>
                <h3><a href="${entry.issuePath}">${escapeHtml(entry.issueIdentifier)}</a></h3>
                <p>${escapeHtml(entry.lastActivityMessage)}</p>
            </div>
            <span class="pill">${escapeHtml(entry.source)}</span>
        </header>
        <dl class="entry-grid">
            <div><dt>Runtime</dt><dd>${escapeHtml(entry.runtime)}</dd></div>
            <div><dt>Idle</dt><dd>${escapeHtml(entry.idle)}</dd></div>
            <div><dt>Total tokens</dt><dd>${escapeHtml(entry.totalTokens)}</dd></div>
            <div><dt>Last turn</dt><dd>${escapeHtml(entry.lastTurnTokens)}</dd></div>
            <div><dt>Context headroom</dt><dd>${escapeHtml(entry.contextHeadroom)}</dd></div>
            <div><dt>Utilization</dt><dd>${escapeHtml(entry.utilization)}</dd></div>
            <div><dt>Last activity</dt><dd>${escapeHtml(entry.lastActivityAt)}</dd></div>
            <div><dt>Session</dt><dd>${escapeHtml(entry.session)}</dd></div>
        </dl>
        <section>
            <h4>Recent trace</h4>
            ${traceItems}
        </section>
    </article>`;
}

function renderRetryEntry(entry: PresentedRetryEntry): string {
    return `<tr>
        <td><a href="${entry.issuePath}">${escapeHtml(entry.issueIdentifier)}</a></td>
        <td>${escapeHtml(entry.attempt)}</td>
        <td>${escapeHtml(entry.reason)}</td>
        <td>${escapeHtml(entry.retryAt)}</td>
        <td>${escapeHtml(entry.errorClass)}</td>
    </tr>`;
}

function renderRecentEvent(event: PresentedRecentEvent): string {
    return `<li class="event-item">
        <div class="event-item__meta">
            <a href="${event.issuePath}">${escapeHtml(event.issueIdentifier)}</a>
            <span>${escapeHtml(event.at)}</span>
        </div>
        <p><strong>${escapeHtml(event.eventType)}</strong> ${escapeHtml(event.message)}</p>
    </li>`;
}

export function renderDashboard(snapshot: OrchestratorSnapshot): string {
    const presented = presentStateSnapshot(snapshot);
    const healthMarkup = `<ul class="health-list">${presented.health.indicators
        .map((indicator) => renderHealthIndicator(indicator))
        .join('')}</ul>`;
    const runningMarkup =
        presented.running.length > 0
            ? presented.running.map((entry) => renderRunningEntry(entry)).join('')
            : '<p class="empty-state">No issues are currently running.</p>';
    const retryMarkup =
        presented.retrying.length > 0
            ? `<table class="retry-table"><thead><tr><th>Issue</th><th>Attempt</th><th>Reason</th><th>Retry at</th><th>Error</th></tr></thead><tbody>${presented.retrying
                  .map((entry) => renderRetryEntry(entry))
                  .join('')}</tbody></table>`
            : '<p class="empty-state">No retry queue entries.</p>';
    const rateLimitMarkup =
        presented.rateLimits.length > 0
            ? `<dl class="rate-limit-list">${presented.rateLimits
                  .map((entry) => `<div><dt>${escapeHtml(entry.key)}</dt><dd>${escapeHtml(entry.value)}</dd></div>`)
                  .join('')}</dl>`
            : '<p class="empty-state">No rate-limit data reported.</p>';
    const recentEventMarkup =
        presented.recentEvents.length > 0
            ? `<ol class="event-list">${presented.recentEvents.map((event) => renderRecentEvent(event)).join('')}</ol>`
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
                <div class="pill">Generated ${escapeHtml(presented.generatedAt)}</div>
            </div>
            <div class="hero-links">
                <a href="/api/v1/state">Snapshot JSON</a>
            </div>
            <div class="meta-strip">
                <section class="meta-card">
                    <dl>
                        <div><dt>Last tick</dt><dd>${escapeHtml(presented.lastTickAt)}</dd></div>
                        <div><dt>Running</dt><dd>${escapeHtml(presented.counts.running)}</dd></div>
                        <div><dt>Retrying</dt><dd>${escapeHtml(presented.counts.retrying)}</dd></div>
                    </dl>
                </section>
                <section class="meta-card">
                    <dl>
                        <div><dt>Completed</dt><dd>${escapeHtml(presented.counts.completed)}</dd></div>
                        <div><dt>Input required</dt><dd>${escapeHtml(presented.counts.inputRequired)}</dd></div>
                        <div><dt>Failed</dt><dd>${escapeHtml(presented.counts.failed)}</dd></div>
                    </dl>
                </section>
            </div>
            <div class="metric-grid">
                ${presented.metricCards.map((card) => renderMetricCard(card.label, card.value, card.hint)).join('')}
            </div>
        </section>
        <div class="stack">
            <div class="secondary-grid">
                <section class="panel">
                    <div class="section-header">
                        <h2>Health</h2>
                        <p class="muted">Overall ${escapeHtml(presented.health.overall)}</p>
                    </div>
                    ${healthMarkup}
                </section>
                <section class="panel">
                    <div class="section-header">
                        <h2>Recent Events</h2>
                        <p class="muted">${escapeHtml(presented.counts.recentEvents)} newest entries</p>
                    </div>
                    ${recentEventMarkup}
                </section>
            </div>
            <section class="panel">
                <div class="section-header">
                    <h2>Running Issues</h2>
                    <p class="muted">${escapeHtml(presented.counts.runningEntries)} active</p>
                </div>
                <div class="running-grid">${runningMarkup}</div>
            </section>
            <div class="secondary-grid">
                <section class="panel">
                    <div class="section-header">
                        <h2>Retry Queue</h2>
                        <p class="muted">${escapeHtml(presented.counts.retryingEntries)} waiting</p>
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
