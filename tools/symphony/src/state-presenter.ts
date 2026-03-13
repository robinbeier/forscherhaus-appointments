import type {OrchestratorSnapshot} from './orchestrator.js';

export interface PresentedMetricCard {
    label: string;
    value: string;
    hint: string;
}

export interface PresentedHealthIndicator {
    status: 'ok' | 'warning' | 'error';
    code: string;
    message: string;
}

export interface PresentedRunningTraceEntry {
    eventType: string;
    message: string;
}

export interface PresentedRunningEntry {
    issueIdentifier: string;
    issuePath: string;
    source: string;
    lastActivityMessage: string;
    runtime: string;
    idle: string;
    totalTokens: string;
    lastTurnTokens: string;
    contextHeadroom: string;
    utilization: string;
    lastActivityAt: string;
    session: string;
    turnCount: string;
    traceTail: PresentedRunningTraceEntry[];
}

export interface PresentedRetryEntry {
    issueIdentifier: string;
    issuePath: string;
    attempt: string;
    reason: string;
    retryAt: string;
    errorClass: string;
}

export interface PresentedRecentEvent {
    issueIdentifier: string;
    issuePath: string;
    at: string;
    eventType: string;
    message: string;
}

export interface PresentedRateLimitEntry {
    key: string;
    value: string;
}

export interface PresentedStateSnapshot {
    generatedAt: string;
    lastTickAt: string;
    counts: {
        running: string;
        retrying: string;
        completed: string;
        inputRequired: string;
        failed: string;
        runningEntries: string;
        retryingEntries: string;
        recentEvents: string;
    };
    totals: {
        runtimeSeconds: string;
        inputTokens: string;
        outputTokens: string;
        totalTokens: string;
        throughputTokensPerSecond: string;
    };
    metricCards: PresentedMetricCard[];
    health: {
        overall: string;
        indicators: PresentedHealthIndicator[];
    };
    running: PresentedRunningEntry[];
    retrying: PresentedRetryEntry[];
    recentEvents: PresentedRecentEvent[];
    rateLimits: PresentedRateLimitEntry[];
}

let integerFormatter: Intl.NumberFormat | undefined;
const decimalFormatters = new Map<number, Intl.NumberFormat>();

function getIntegerFormatter(): Intl.NumberFormat {
    if (!integerFormatter) {
        integerFormatter = new Intl.NumberFormat('en-US');
    }

    return integerFormatter;
}

function getDecimalFormatter(maximumFractionDigits: number): Intl.NumberFormat {
    const cached = decimalFormatters.get(maximumFractionDigits);
    if (cached) {
        return cached;
    }

    const formatter = new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 0,
        maximumFractionDigits,
    });
    decimalFormatters.set(maximumFractionDigits, formatter);

    return formatter;
}

export function resetNumberFormattersForTests(): void {
    // Test hook: keep formatter cache deterministic across assertions.
    integerFormatter = undefined;
    decimalFormatters.clear();
}

function asFiniteNumber(value: number | null | undefined): number | null {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return null;
    }

    return value;
}

export function formatInteger(value: number | null | undefined): string {
    const normalized = asFiniteNumber(value);
    if (normalized === null) {
        return 'n/a';
    }

    return getIntegerFormatter().format(Math.floor(normalized));
}

export function formatDecimal(value: number | null | undefined, maximumFractionDigits = 1): string {
    const normalized = asFiniteNumber(value);
    if (normalized === null) {
        return 'n/a';
    }

    return getDecimalFormatter(maximumFractionDigits).format(normalized);
}

export function formatPercent(value: number | null | undefined): string {
    const normalized = asFiniteNumber(value);
    if (normalized === null) {
        return 'n/a';
    }

    return `${formatDecimal(normalized)}%`;
}

export function formatDuration(seconds: number | null | undefined): string {
    const normalized = asFiniteNumber(seconds);
    if (normalized === null) {
        return 'n/a';
    }

    const totalSeconds = Math.max(0, Math.floor(normalized));
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

export function formatTimestamp(value: string | undefined): string {
    if (!value) {
        return 'Never';
    }

    const parsed = Date.parse(value);
    if (Number.isNaN(parsed)) {
        return value;
    }

    return new Date(parsed).toISOString().replace('.000Z', 'Z');
}

export function formatRateLimitValue(value: unknown): string {
    if (typeof value === 'number') {
        return formatDecimal(value, 2);
    }

    if (typeof value === 'string' || typeof value === 'boolean') {
        return String(value);
    }

    if (value === null || value === undefined) {
        return 'n/a';
    }

    const serialized = JSON.stringify(value);
    return serialized ?? 'n/a';
}

function formatThroughput(totalTokens: number, runtimeSeconds: number): string {
    if (!Number.isFinite(totalTokens) || !Number.isFinite(runtimeSeconds) || runtimeSeconds <= 0) {
        return 'n/a';
    }

    return formatDecimal(totalTokens / runtimeSeconds, 2);
}

export function presentStateSnapshot(snapshot: OrchestratorSnapshot): PresentedStateSnapshot {
    const totals = {
        runtimeSeconds: formatDuration(snapshot.totals.runtime_seconds),
        inputTokens: formatInteger(snapshot.totals.input_tokens),
        outputTokens: formatInteger(snapshot.totals.output_tokens),
        totalTokens: formatInteger(snapshot.totals.total_tokens),
        throughputTokensPerSecond: formatThroughput(snapshot.totals.total_tokens, snapshot.totals.runtime_seconds),
    };

    return {
        generatedAt: formatTimestamp(snapshot.generated_at),
        lastTickAt: formatTimestamp(snapshot.last_tick_at),
        counts: {
            running: formatInteger(snapshot.counts.running),
            retrying: formatInteger(snapshot.counts.retrying),
            completed: formatInteger(snapshot.counts.completed),
            inputRequired: formatInteger(snapshot.counts.input_required),
            failed: formatInteger(snapshot.counts.failed),
            runningEntries: formatInteger(snapshot.running.length),
            retryingEntries: formatInteger(snapshot.retrying.length),
            recentEvents: formatInteger(snapshot.recent_events.length),
        },
        totals,
        metricCards: [
            {
                label: 'Runtime total',
                value: totals.runtimeSeconds,
                hint: 'Aggregate runtime across active entries.',
            },
            {
                label: 'Input tokens',
                value: totals.inputTokens,
                hint: 'Prompt and context consumption observed so far.',
            },
            {
                label: 'Output tokens',
                value: totals.outputTokens,
                hint: 'Model output produced across active entries.',
            },
            {
                label: 'Total tokens',
                value: totals.totalTokens,
                hint: 'Combined live token spend across active entries.',
            },
        ],
        health: {
            overall: snapshot.health.overall,
            indicators: snapshot.health.indicators.map((indicator) => ({
                status: indicator.status,
                code: indicator.code,
                message: indicator.message,
            })),
        },
        running: snapshot.running.map((entry) => ({
            issueIdentifier: entry.issue_identifier,
            issuePath: `/api/v1/${encodeURIComponent(entry.issue_identifier)}`,
            source: entry.source,
            lastActivityMessage: entry.last_activity ?? 'No recent activity message.',
            runtime: formatDuration(entry.runtime_seconds),
            idle: formatDuration(entry.idle_seconds),
            totalTokens: formatInteger(entry.total_tokens),
            lastTurnTokens: formatInteger(entry.last_turn_tokens),
            contextHeadroom: formatInteger(entry.context_headroom_tokens),
            utilization: formatPercent(entry.context_utilization_percent),
            lastActivityAt: formatTimestamp(entry.last_activity_at),
            session: entry.session_id ?? 'n/a',
            turnCount: formatInteger(entry.turn_count),
            traceTail: entry.trace_tail.map((trace) => ({
                eventType: trace.eventType,
                message: trace.message,
            })),
        })),
        retrying: snapshot.retrying.map((entry) => ({
            issueIdentifier: entry.issue_identifier,
            issuePath: `/api/v1/${encodeURIComponent(entry.issue_identifier)}`,
            attempt: String(entry.attempt),
            reason: entry.reason,
            retryAt: formatTimestamp(entry.available_at),
            errorClass: entry.error_class ?? 'n/a',
        })),
        recentEvents: snapshot.recent_events.map((event) => ({
            issueIdentifier: event.issue_identifier,
            issuePath: `/api/v1/${encodeURIComponent(event.issue_identifier)}`,
            at: formatTimestamp(event.atIso),
            eventType: event.eventType,
            message: event.message,
        })),
        rateLimits: Object.entries(snapshot.rate_limits).map(([key, value]) => ({
            key,
            value: formatRateLimitValue(value),
        })),
    };
}
