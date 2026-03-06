import type {Logger} from './logger.js';
import type {OrchestratorSnapshot} from './orchestrator.js';
import {SymphonyOrchestrator} from './orchestrator.js';
import {SymphonyStateServer} from './state-server.js';
import type {WorkflowConfigStore} from './workflow.js';

interface SymphonyServiceArgs {
    logger: Logger;
    workflowConfigStore: WorkflowConfigStore;
}

const MIN_POLL_INTERVAL_MS = 1000;
const DEFAULT_STATE_API_HOST = '127.0.0.1';
const DEFAULT_STATE_API_PORT = 8787;

function sanitizePollInterval(intervalMs: number): number {
    if (!Number.isFinite(intervalMs)) {
        return MIN_POLL_INTERVAL_MS;
    }

    return Math.max(MIN_POLL_INTERVAL_MS, Math.floor(intervalMs));
}

function parseStateApiEnabled(rawValue: string | undefined): boolean {
    if (!rawValue) {
        return false;
    }

    return rawValue === '1' || rawValue.toLowerCase() === 'true';
}

function sanitizeStateApiPort(rawValue: string | undefined): number {
    const parsed = Number(rawValue);
    if (!Number.isFinite(parsed)) {
        return DEFAULT_STATE_API_PORT;
    }

    const integerPort = Math.floor(parsed);
    if (integerPort < 1 || integerPort > 65535) {
        return DEFAULT_STATE_API_PORT;
    }

    return integerPort;
}

export class SymphonyService {
    private readonly logger: Logger;
    private readonly workflowConfigStore: WorkflowConfigStore;
    private readonly orchestrator: SymphonyOrchestrator;
    private readonly stateServer: SymphonyStateServer;
    private running = false;
    private pollTimer?: NodeJS.Timeout;
    private pollInFlight?: Promise<void>;

    public constructor(args: SymphonyServiceArgs) {
        this.logger = args.logger;
        this.workflowConfigStore = args.workflowConfigStore;
        this.orchestrator = new SymphonyOrchestrator({
            logger: args.logger,
            workflowConfigStore: args.workflowConfigStore,
        });

        this.stateServer = new SymphonyStateServer({
            enabled: parseStateApiEnabled(process.env.SYMPHONY_STATE_API_ENABLED),
            logger: args.logger,
            host: process.env.SYMPHONY_STATE_API_HOST ?? DEFAULT_STATE_API_HOST,
            port: sanitizeStateApiPort(process.env.SYMPHONY_STATE_API_PORT),
            getSnapshot: () => this.getSnapshot(),
            refresh: async () => this.refreshNow(),
        });
    }

    public async start(): Promise<void> {
        if (this.running) {
            return;
        }

        const currentConfig = this.workflowConfigStore.getCurrentConfig();

        this.running = true;
        this.logger.info('Symphony service started', {
            workflowPath: currentConfig.workflowPath,
            pid: process.pid,
        });

        try {
            await this.stateServer.start();
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            this.logger.error('State API failed to start. Continuing without state API.', {error: message});
        }
        this.scheduleNextTick(0);
    }

    public async stop(reason: string): Promise<void> {
        if (!this.running) {
            return;
        }

        this.running = false;

        if (this.pollTimer) {
            clearTimeout(this.pollTimer);
            this.pollTimer = undefined;
        }

        if (this.pollInFlight) {
            await this.pollInFlight;
            this.pollInFlight = undefined;
        }

        await this.orchestrator.shutdown();
        try {
            await this.stateServer.stop();
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            this.logger.error('State API failed to stop cleanly.', {error: message});
        }

        this.logger.info('Symphony service stopped', {reason});
    }

    public getSnapshot(): OrchestratorSnapshot {
        return this.orchestrator.getSnapshot();
    }

    public async refreshNow(): Promise<void> {
        await this.orchestrator.runTick();
    }

    private scheduleNextTick(delayMs: number): void {
        if (!this.running) {
            return;
        }

        this.pollTimer = setTimeout(
            () => {
                this.pollTimer = undefined;
                void this.runPollTick();
            },
            Math.max(0, delayMs),
        );
    }

    private async runPollTick(): Promise<void> {
        this.pollInFlight = this.orchestrator
            .runTick()
            .catch((error) => {
                const message = error instanceof Error ? error.message : String(error);
                this.logger.error('Orchestrator poll tick failed', {error: message});
            })
            .finally(() => {
                this.pollInFlight = undefined;
            });

        await this.pollInFlight;

        if (!this.running) {
            return;
        }

        const reloadedConfig = this.workflowConfigStore.getCurrentConfig();
        const snapshot = this.orchestrator.getSnapshot();
        this.logger.info('Symphony service heartbeat', {
            workflowPath: reloadedConfig.workflowPath,
            runningCount: snapshot.running.length,
            retryCount: snapshot.retrying.length,
        });

        this.scheduleNextTick(sanitizePollInterval(reloadedConfig.polling.intervalMs));
    }
}
