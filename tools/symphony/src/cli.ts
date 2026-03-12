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
                id: 'check-issue-id',
                identifier: 'CHECK-ISSUE',
                title: 'Symphony check mode',
                title_or_identifier: 'Symphony check mode',
                state: 'In Progress',
                description: 'Synthetic issue payload for workflow validation.',
                description_or_default: 'Synthetic issue payload for workflow validation.',
                branch_name: 'codex/symphony-check-issue',
                branch_name_or_default: 'codex/symphony-check-issue',
                url: 'https://linear.app/check/issue/CHECK-ISSUE',
                labels: [],
                blocked_by: [],
                blocked_by_identifiers: [],
                created_at: '2026-03-07T00:00:00.000Z',
                updated_at: '2026-03-07T00:00:00.000Z',
                project_slug: loadedConfig.tracker.projectSlug,
                workpad_comment_id: 'check-workpad',
                workpad_comment_body: '## Codex Workpad',
                workpad_comment_body_or_default: '## Codex Workpad',
                workpad_comment_url: 'https://linear.app/check/comment/check-workpad',
                target_paths: ['docs/symphony/STAGING_PILOT_RUNBOOK.md'],
                target_paths_hint_or_default: '- docs/symphony/STAGING_PILOT_RUNBOOK.md',
                first_repo_target_path: 'docs/symphony/STAGING_PILOT_RUNBOOK.md',
                first_repo_target_path_or_default: 'docs/symphony/STAGING_PILOT_RUNBOOK.md',
                first_repo_step_contract:
                    'Before broader exploration, open and edit `docs/symphony/STAGING_PILOT_RUNBOOK.md`. Produce the smallest valid repo diff there in this first turn. Keep the first diff in docs scope unless the issue explicitly requires more. The runtime will stop and retry the turn if no repo diff appears.',
                first_repo_step_contract_or_default:
                    'Before broader exploration, open and edit `docs/symphony/STAGING_PILOT_RUNBOOK.md`. Produce the smallest valid repo diff there in this first turn. Keep the first diff in docs scope unless the issue explicitly requires more. The runtime will stop and retry the turn if no repo diff appears.',
            },
            attempt: 1,
        });

        logger.info('Check mode complete');
        return;
    }

    const service = new SymphonyService({
        logger,
        workflowConfigStore,
        cliStateApiPort: options.stateApiPort,
    });
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
