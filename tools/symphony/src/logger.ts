export interface Logger {
    info(message: string, fields?: Record<string, unknown>): void;
    warn(message: string, fields?: Record<string, unknown>): void;
    error(message: string, fields?: Record<string, unknown>): void;
}

type Level = 'info' | 'warn' | 'error';

function emit(level: Level, message: string, fields?: Record<string, unknown>): void {
    const record = {
        ts: new Date().toISOString(),
        level,
        message,
        ...fields,
    };

    const serialized = JSON.stringify(record);

    if (level === 'error') {
        console.error(serialized);
        return;
    }

    console.log(serialized);
}

export function createLogger(): Logger {
    return {
        info(message, fields) {
            emit('info', message, fields);
        },
        warn(message, fields) {
            emit('warn', message, fields);
        },
        error(message, fields) {
            emit('error', message, fields);
        },
    };
}
