import type {Logger} from './logger.js';
import {SymphonyOrchestrator} from './orchestrator.js';
import type {WorkflowConfigStore} from './workflow.js';

interface SymphonyServiceArgs {
    logger: Logger;
    workflowConfigStore: WorkflowConfigStore;
}

const MIN_POLL_INTERVAL_MS = 1000;

function sanitizePollInterval(intervalMs: number): number {
    if (!Number.isFinite(intervalMs)) {
        return MIN_POLL_INTERVAL_MS;
    }

    return Math.max(MIN_POLL_INTERVAL_MS, Math.floor(intervalMs));
}

export class SymphonyService {
    private readonly logger: Logger;
    private readonly workflowConfigStore: WorkflowConfigStore;
    private readonly orchestrator: SymphonyOrchestrator;
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

        this.logger.info('Symphony service stopped', {reason});
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
        this.logger.info('Symphony service heartbeat', {
            workflowPath: reloadedConfig.workflowPath,
            runningCount: this.orchestrator.getSnapshot().running.length,
            retryCount: this.orchestrator.getSnapshot().retrying.length,
        });

        this.scheduleNextTick(sanitizePollInterval(reloadedConfig.polling.intervalMs));
    }
}
