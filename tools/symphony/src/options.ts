export interface CliOptions {
    checkOnly: boolean;
    workflowPath?: string;
    stateApiPort?: number;
}

function parsePortValue(rawValue: string, optionName: string): number {
    const parsed = Number(rawValue);
    if (!Number.isFinite(parsed)) {
        throw new Error(`Invalid value for ${optionName}: ${rawValue}`);
    }

    const port = Math.floor(parsed);
    if (port < 1 || port > 65535) {
        throw new Error(`Invalid value for ${optionName}: ${rawValue}`);
    }

    return port;
}

export function parseCliOptions(argv: string[]): CliOptions {
    const options: CliOptions = {
        checkOnly: false,
    };
    let positionalWorkflowPathConsumed = false;

    for (let index = 0; index < argv.length; index += 1) {
        const token = argv[index];

        if (!token.startsWith('-')) {
            if (positionalWorkflowPathConsumed || options.workflowPath) {
                throw new Error(`Unexpected positional argument: ${token}`);
            }

            options.workflowPath = token;
            positionalWorkflowPathConsumed = true;
            continue;
        }

        if (token === '--check') {
            options.checkOnly = true;
            continue;
        }

        if (token === '--workflow' || token === '-w') {
            const value = argv[index + 1];
            if (!value || value.startsWith('-')) {
                throw new Error(`Missing value for ${token}`);
            }
            options.workflowPath = value;
            index += 1;
            continue;
        }

        if (token.startsWith('--workflow=')) {
            const value = token.slice('--workflow='.length);
            if (!value) {
                throw new Error('Missing value for --workflow');
            }
            options.workflowPath = value;
            continue;
        }

        if (token === '--port') {
            const value = argv[index + 1];
            if (!value || value.startsWith('-')) {
                throw new Error('Missing value for --port');
            }
            options.stateApiPort = parsePortValue(value, '--port');
            index += 1;
            continue;
        }

        if (token.startsWith('--port=')) {
            const value = token.slice('--port='.length);
            if (!value) {
                throw new Error('Missing value for --port');
            }
            options.stateApiPort = parsePortValue(value, '--port');
            continue;
        }

        throw new Error(`Unknown argument: ${token}`);
    }

    return options;
}
