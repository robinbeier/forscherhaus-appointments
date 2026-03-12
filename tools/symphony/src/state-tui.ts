import type {OrchestratorSnapshot} from './orchestrator.js';
import {presentStateSnapshot} from './state-presenter.js';

export interface TerminalStatusViewOptions {
    projectSlug?: string;
    dashboardUrl?: string;
    nextRefreshSeconds?: number;
}

interface TerminalStatusRuntime {
    snapshot: OrchestratorSnapshot;
    options?: TerminalStatusViewOptions;
}

function clampWidth(value: string, width: number): string {
    if (value.length <= width) {
        return value.padEnd(width, ' ');
    }

    if (width <= 1) {
        return value.slice(0, width);
    }

    return `${value.slice(0, width - 1)}…`;
}

function joinColumns(columns: string[], widths: number[]): string {
    return columns
        .map((column, index) => clampWidth(column, widths[index] ?? column.length))
        .join('  ')
        .trimEnd();
}

function formatRateLimitsLine(rateLimits: Array<{key: string; value: string}>): string {
    if (rateLimits.length === 0) {
        return 'unavailable';
    }

    return rateLimits
        .map((entry) => `${entry.key}=${entry.value}`)
        .join(', ')
        .slice(0, 220);
}

function normalizeNextRefreshSeconds(nextRefreshSeconds: number | undefined): string {
    if (!Number.isFinite(nextRefreshSeconds)) {
        return 'n/a';
    }

    const normalized = Math.max(0, Math.floor(nextRefreshSeconds ?? 0));
    return `${normalized}s`;
}

export function renderTerminalStatusView(args: TerminalStatusRuntime): string {
    const presented = presentStateSnapshot(args.snapshot);
    const options = args.options ?? {};
    const lines: string[] = [];

    lines.push('SYMPHONY STATUS');
    lines.push(`Agents: ${presented.counts.running}`);
    lines.push(`Throughput: ${presented.totals.throughputTokensPerSecond} tps`);
    lines.push(`Runtime: ${presented.totals.runtimeSeconds}`);
    lines.push(
        `Tokens: in ${presented.totals.inputTokens} | out ${presented.totals.outputTokens} | total ${presented.totals.totalTokens}`,
    );
    lines.push(`Rate Limits: ${formatRateLimitsLine(presented.rateLimits)}`);
    lines.push(`Project: ${options.projectSlug ?? 'n/a'}`);
    lines.push(`Dashboard: ${options.dashboardUrl ?? 'disabled'}`);
    lines.push(`Next refresh: ${normalizeNextRefreshSeconds(options.nextRefreshSeconds)}`);
    lines.push('');

    lines.push('RUNNING');
    lines.push(joinColumns(['ID', 'STAGE', 'AGE / TURN', 'TOKENS', 'SESSION'], [16, 14, 18, 12, 24]));
    if (presented.running.length === 0) {
        lines.push('No running issues.');
    } else {
        for (const entry of presented.running) {
            lines.push(
                joinColumns(
                    [
                        entry.issueIdentifier,
                        entry.source,
                        `${entry.runtime} / ${entry.turnCount}`,
                        entry.totalTokens,
                        entry.session,
                    ],
                    [16, 14, 18, 12, 24],
                ),
            );
        }
    }

    lines.push('');
    lines.push('BACKOFF QUEUE');
    lines.push(joinColumns(['ID', 'ATTEMPT', 'REASON', 'RETRY AT'], [16, 8, 22, 28]));
    if (presented.retrying.length === 0) {
        lines.push('No queued retries.');
    } else {
        for (const entry of presented.retrying) {
            lines.push(
                joinColumns([entry.issueIdentifier, entry.attempt, entry.reason, entry.retryAt], [16, 8, 22, 28]),
            );
        }
    }

    return lines.join('\n');
}

export interface SymphonyStateTuiArgs {
    write?: (chunk: string) => void;
}

const TERMINAL_RESET = '\u001b[2J\u001b[H';

export class SymphonyStateTui {
    private readonly write: (chunk: string) => void;
    private active = false;

    public constructor(args: SymphonyStateTuiArgs = {}) {
        this.write = args.write ?? ((chunk) => process.stdout.write(chunk));
    }

    public start(snapshot: OrchestratorSnapshot, options?: TerminalStatusViewOptions): void {
        this.active = true;
        this.render(snapshot, options);
    }

    public render(snapshot: OrchestratorSnapshot, options?: TerminalStatusViewOptions): void {
        if (!this.active) {
            return;
        }

        const body = renderTerminalStatusView({
            snapshot,
            options,
        });
        this.write(`${TERMINAL_RESET}${body}\n`);
    }

    public stop(): void {
        if (!this.active) {
            return;
        }

        this.active = false;
        this.write('\n');
    }
}
