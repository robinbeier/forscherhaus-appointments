import type {Logger} from './logger.js';
import type {WorkflowConfigStore} from './workflow.js';

interface SymphonyServiceArgs {
    logger: Logger;
    workflowConfigStore: WorkflowConfigStore;
}

export class SymphonyService {
    private readonly logger: Logger;
    private readonly workflowConfigStore: WorkflowConfigStore;
    private running = false;
    private heartbeatTimer?: NodeJS.Timeout;

    public constructor(args: SymphonyServiceArgs) {
        this.logger = args.logger;
        this.workflowConfigStore = args.workflowConfigStore;
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

        this.heartbeatTimer = setInterval(() => {
            void this.workflowConfigStore.reloadIfChanged();

            this.logger.info('Symphony service heartbeat', {
                workflowPath: currentConfig.workflowPath,
            });
        }, 30000);
    }

    public async stop(reason: string): Promise<void> {
        if (!this.running) {
            return;
        }

        this.running = false;

        if (this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = undefined;
        }

        this.logger.info('Symphony service stopped', {reason});
    }
}
