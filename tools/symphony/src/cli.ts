import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {createLogger} from './logger.js';
import {parseCliOptions} from './options.js';
import {SymphonyService} from './service.js';
import {resolveWorkflowPath, WorkflowConfigStore} from './workflow.js';

async function run(argv: string[]): Promise<void> {
    const logger = createLogger();
    const options = parseCliOptions(argv);
    const moduleDir = path.dirname(fileURLToPath(import.meta.url));

    const workflowPath = resolveWorkflowPath({
        cliWorkflowPath: options.workflowPath,
        cwd: process.cwd(),
        moduleDir,
    });

    const workflowConfigStore = new WorkflowConfigStore({
        workflowPath,
        logger,
    });

    const loadedConfig = await workflowConfigStore.initialize();
    workflowConfigStore.validateCurrentPreflight();

    logger.info('Workflow loaded', {
        workflowPath: loadedConfig.workflowPath,
        source: options.workflowPath ? 'cli' : 'default',
        bytes: Buffer.byteLength(loadedConfig.promptTemplate, 'utf8'),
        trackerProvider: loadedConfig.tracker.provider,
        pollingIntervalMs: loadedConfig.polling.intervalMs,
    });

    if (options.checkOnly) {
        await workflowConfigStore.buildDispatchPrompt({
            issue: {
                identifier: 'CHECK-ISSUE',
                title: 'Symphony check mode',
                state: 'In Progress',
            },
            attempt: 1,
        });

        logger.info('Check mode complete');
        return;
    }

    const service = new SymphonyService({logger, workflowConfigStore});
    await service.start();

    logger.info('Symphony service is running. Press Ctrl+C to stop.');

    await new Promise<void>((resolve, reject) => {
        let finished = false;

        const shutdown = async (signal: string): Promise<void> => {
            if (finished) {
                return;
            }

            finished = true;
            logger.info('Shutdown signal received', {signal});

            try {
                await service.stop(signal);
                resolve();
            } catch (error) {
                reject(error);
            }
        };

        process.once('SIGINT', () => {
            void shutdown('SIGINT');
        });

        process.once('SIGTERM', () => {
            void shutdown('SIGTERM');
        });
    });
}

export async function main(argv: string[]): Promise<void> {
    try {
        await run(argv);
    } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        createLogger().error('Symphony bootstrap failed', {error: message});
        process.exitCode = 1;
    }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    void main(process.argv.slice(2));
}
