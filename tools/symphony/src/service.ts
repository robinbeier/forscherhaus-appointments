import type {Logger} from './logger.js';
import type {OrchestratorSnapshot} from './orchestrator.js';
import {SymphonyOrchestrator} from './orchestrator.js';
import {SymphonyStateServer} from './state-server.js';
import type {LoadedWorkflowConfig, WorkflowConfigStore} from './workflow.js';

interface SymphonyServiceArgs {
    logger: Logger;
    workflowConfigStore: WorkflowConfigStore;
    cliStateApiPort?: number;
}

const MIN_POLL_INTERVAL_MS = 1000;
const DEFAULT_STATE_API_HOST = '127.0.0.1';
const DEFAULT_STATE_API_PORT = 8787;

export interface ResolvedStateApiConfig {
    enabled: boolean;
    host: string;
    port: number;
    source: 'disabled' | 'cli' | 'workflow' | 'env';
}

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

function sanitizeStateApiPort(rawValue: number | string | undefined): number | undefined {
    if (rawValue === undefined) {
        return undefined;
    }

    const parsed = Number(rawValue);
    if (!Number.isFinite(parsed)) {
        return undefined;
    }

    const port = Math.floor(parsed);
    if (port < 1 || port > 65535) {
        return undefined;
    }

    return port;
}

export function resolveStateApiConfig(args: {
    workflowConfig: LoadedWorkflowConfig;
    env: NodeJS.ProcessEnv;
    cliStateApiPort?: number;
}): ResolvedStateApiConfig {
    const workflowHost = args.workflowConfig.server.host ?? DEFAULT_STATE_API_HOST;
    const cliHost = args.workflowConfig.server.host ?? args.env.SYMPHONY_STATE_API_HOST ?? DEFAULT_STATE_API_HOST;
    const cliPort = sanitizeStateApiPort(args.cliStateApiPort);
    if (cliPort !== undefined) {
        return {
            enabled: true,
            host: cliHost,
            port: cliPort,
            source: 'cli',
        };
    }

    const workflowPort = sanitizeStateApiPort(args.workflowConfig.server.port);
    if (workflowPort !== undefined) {
        return {
            enabled: true,
            host: workflowHost,
            port: workflowPort,
            source: 'workflow',
        };
    }

    const envEnabled = parseStateApiEnabled(args.env.SYMPHONY_STATE_API_ENABLED);
    if (envEnabled) {
        return {
            enabled: true,
            host: args.env.SYMPHONY_STATE_API_HOST ?? DEFAULT_STATE_API_HOST,
            port: sanitizeStateApiPort(args.env.SYMPHONY_STATE_API_PORT) ?? DEFAULT_STATE_API_PORT,
            source: 'env',
        };
    }

    return {
        enabled: false,
        host: args.workflowConfig.server.host ?? DEFAULT_STATE_API_HOST,
        port:
            sanitizeStateApiPort(args.workflowConfig.server.port) ??
            sanitizeStateApiPort(args.env.SYMPHONY_STATE_API_PORT) ??
            DEFAULT_STATE_API_PORT,
        source: 'disabled',
    };
}

export class SymphonyService {
    private readonly logger: Logger;
    private readonly workflowConfigStore: WorkflowConfigStore;
    private readonly orchestrator: SymphonyOrchestrator;
    private readonly cliStateApiPort?: number;
    private readonly stateApiConfig: ResolvedStateApiConfig;
    private readonly stateServer: SymphonyStateServer;
    private running = false;
    private pollTimer?: NodeJS.Timeout;
    private pollInFlight?: Promise<void>;
    private loggedStateApiReloadWarning = false;

    public constructor(args: SymphonyServiceArgs) {
        this.logger = args.logger;
        this.workflowConfigStore = args.workflowConfigStore;
        this.cliStateApiPort = args.cliStateApiPort;
        this.orchestrator = new SymphonyOrchestrator({
            logger: args.logger,
            workflowConfigStore: args.workflowConfigStore,
        });

        const currentConfig = this.workflowConfigStore.getCurrentConfig();
        this.stateApiConfig = resolveStateApiConfig({
            workflowConfig: currentConfig,
            env: process.env,
            cliStateApiPort: this.cliStateApiPort,
        });

        this.stateServer = new SymphonyStateServer({
            enabled: this.stateApiConfig.enabled,
            logger: args.logger,
            host: this.stateApiConfig.host,
            port: this.stateApiConfig.port,
            getSnapshot: () => this.getSnapshot(),
            getIssueDetails: (issueIdentifier) => this.orchestrator.getIssueDetails(issueIdentifier),
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
            stateApiSource: this.stateApiConfig.source,
            stateApiEnabled: this.stateApiConfig.enabled,
        });

        try {
            await this.stateServer.start();
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            this.logger.error('State API failed to start. Continuing without state API.', {error: message});
        }

        await this.orchestrator.runStartupCleanup();
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
        this.logStateApiReloadRequirementIfNeeded(reloadedConfig);
        const snapshot = this.orchestrator.getSnapshot();
        this.logger.info('Symphony service heartbeat', {
            workflowPath: reloadedConfig.workflowPath,
            runningCount: snapshot.running.length,
            retryCount: snapshot.retrying.length,
        });

        this.scheduleNextTick(sanitizePollInterval(reloadedConfig.polling.intervalMs));
    }

    private logStateApiReloadRequirementIfNeeded(workflowConfig: LoadedWorkflowConfig): void {
        if (this.loggedStateApiReloadWarning) {
            return;
        }

        const reloadedStateApiConfig = resolveStateApiConfig({
            workflowConfig,
            env: process.env,
            cliStateApiPort: this.cliStateApiPort,
        });

        if (
            reloadedStateApiConfig.enabled === this.stateApiConfig.enabled &&
            reloadedStateApiConfig.host === this.stateApiConfig.host &&
            reloadedStateApiConfig.port === this.stateApiConfig.port &&
            reloadedStateApiConfig.source === this.stateApiConfig.source
        ) {
            return;
        }

        this.loggedStateApiReloadWarning = true;
        this.logger.warn('State API configuration changed; restart Symphony to apply the new binding.', {
            current: this.stateApiConfig,
            requested: reloadedStateApiConfig,
        });
    }
}
