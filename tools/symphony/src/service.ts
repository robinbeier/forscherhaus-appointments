import type {Logger} from './logger.js';

interface SymphonyServiceArgs {
    logger: Logger;
    workflowPath: string;
}

export class SymphonyService {
    private readonly logger: Logger;
    private readonly workflowPath: string;
    private running = false;
    private heartbeatTimer?: NodeJS.Timeout;

    public constructor(args: SymphonyServiceArgs) {
        this.logger = args.logger;
        this.workflowPath = args.workflowPath;
    }

    public async start(): Promise<void> {
        if (this.running) {
            return;
        }

        this.running = true;
        this.logger.info('Symphony service started', {
            workflowPath: this.workflowPath,
            pid: process.pid,
        });

        this.heartbeatTimer = setInterval(() => {
            this.logger.info('Symphony service heartbeat', {
                workflowPath: this.workflowPath,
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
