export interface CliOptions {
    checkOnly: boolean;
    workflowPath?: string;
}

export function parseCliOptions(argv: string[]): CliOptions {
    const options: CliOptions = {
        checkOnly: false,
    };

    for (let index = 0; index < argv.length; index += 1) {
        const token = argv[index];

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

        throw new Error(`Unknown argument: ${token}`);
    }

    return options;
}
