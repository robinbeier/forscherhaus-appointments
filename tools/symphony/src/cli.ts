import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {createLogger} from './logger.js';
import {parseCliOptions} from './options.js';
import {SymphonyService} from './service.js';
import {readWorkflowFile, resolveWorkflowPath} from './workflow.js';

async function run(argv: string[]): Promise<void> {
    const logger = createLogger();
    const options = parseCliOptions(argv);
    const moduleDir = path.dirname(fileURLToPath(import.meta.url));

    const workflowPath = resolveWorkflowPath({
        cliWorkflowPath: options.workflowPath,
        cwd: process.cwd(),
        moduleDir,
    });

    const workflowContents = await readWorkflowFile(workflowPath);

    logger.info('Workflow loaded', {
        workflowPath,
        source: options.workflowPath ? 'cli' : 'default',
        bytes: Buffer.byteLength(workflowContents, 'utf8'),
    });

    const service = new SymphonyService({logger, workflowPath});
    await service.start();

    if (options.checkOnly) {
        logger.info('Check mode complete');
        await service.stop('check-only');
        return;
    }

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
