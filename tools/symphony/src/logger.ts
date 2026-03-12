export interface Logger {
    info(message: string, fields?: Record<string, unknown>): void;
    warn(message: string, fields?: Record<string, unknown>): void;
    error(message: string, fields?: Record<string, unknown>): void;
}

type Level = 'info' | 'warn' | 'error';

type LoggerMode = 'default' | 'tui';
type WriteLine = (line: string) => void;

export interface CreateLoggerOptions {
    mode?: LoggerMode;
    writeStdout?: WriteLine;
    writeStderr?: WriteLine;
}

function emit(
    level: Level,
    message: string,
    fields: Record<string, unknown> | undefined,
    options: Required<CreateLoggerOptions>,
): void {
    const record = {
        ts: new Date().toISOString(),
        level,
        message,
        ...fields,
    };

    const serialized = JSON.stringify(record);

    if (options.mode === 'tui' && level === 'info') {
        return;
    }

    if (options.mode === 'tui' && level === 'warn') {
        options.writeStderr(serialized);
        return;
    }

    if (level === 'error') {
        options.writeStderr(serialized);
        return;
    }

    options.writeStdout(serialized);
}

export function createLogger(options: CreateLoggerOptions = {}): Logger {
    const normalized: Required<CreateLoggerOptions> = {
        mode: options.mode ?? 'default',
        writeStdout: options.writeStdout ?? ((line) => console.log(line)),
        writeStderr: options.writeStderr ?? ((line) => console.error(line)),
    };

    return {
        info(message, fields) {
            emit('info', message, fields, normalized);
        },
        warn(message, fields) {
            emit('warn', message, fields, normalized);
        },
        error(message, fields) {
            emit('error', message, fields, normalized);
        },
    };
}
