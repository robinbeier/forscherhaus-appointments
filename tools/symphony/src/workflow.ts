import {readFile, stat} from 'node:fs/promises';
import path from 'node:path';

interface ResolveWorkflowPathArgs {
    cliWorkflowPath?: string;
    cwd: string;
    moduleDir: string;
}

export function resolveWorkflowPath(args: ResolveWorkflowPathArgs): string {
    if (args.cliWorkflowPath) {
        return path.resolve(args.cwd, args.cliWorkflowPath);
    }

    return path.resolve(args.moduleDir, '../../..', 'WORKFLOW.md');
}

export async function readWorkflowFile(workflowPath: string): Promise<string> {
    let fileStats;

    try {
        fileStats = await stat(workflowPath);
    } catch {
        throw new Error(`Workflow file not found: ${workflowPath}`);
    }

    if (!fileStats.isFile()) {
        throw new Error(`Workflow path is not a file: ${workflowPath}`);
    }

    const contents = await readFile(workflowPath, 'utf8');

    if (contents.trim().length === 0) {
        throw new Error(`Workflow file is empty: ${workflowPath}`);
    }

    return contents;
}
