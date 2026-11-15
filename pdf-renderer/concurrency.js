export function parseMaxConcurrentRenders(value) {
    const candidate = typeof value === 'string' && /^(?:[1-9]|1[0-6])$/.test(value) ? Number(value) : 4;

    return candidate;
}
