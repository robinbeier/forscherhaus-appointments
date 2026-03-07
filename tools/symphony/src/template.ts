export interface PromptTemplateContext {
    issue: Record<string, unknown>;
    attempt: number | null;
}

const TOKEN_PATTERN = /\{\{\s*([^{}]+?)\s*\}\}/g;
const ALLOWED_TEMPLATE_ROOTS = new Set(['issue', 'attempt']);

function resolvePath(root: unknown, segments: string[]): unknown {
    let current = root;

    for (const segment of segments) {
        if (segment.length === 0) {
            throw new Error('Empty template path segment is not allowed.');
        }

        if (current === null || typeof current !== 'object' || !(segment in current)) {
            throw new Error(`Template value "${segments.join('.')}" is not available in dispatch context.`);
        }

        current = (current as Record<string, unknown>)[segment];
    }

    return current;
}

function formatTemplateValue(value: unknown): string {
    if (value === null) {
        return 'null';
    }

    if (value === undefined) {
        throw new Error('Template value is undefined.');
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

export function validateStrictTemplate(template: string): void {
    TOKEN_PATTERN.lastIndex = 0;

    let tokenMatch = TOKEN_PATTERN.exec(template);
    while (tokenMatch !== null) {
        const expression = tokenMatch[1].trim();
        if (expression.length === 0) {
            throw new Error('Template expression cannot be empty.');
        }

        const [root] = expression.split('.');
        if (!ALLOWED_TEMPLATE_ROOTS.has(root)) {
            throw new Error(`Template root "${root}" is not allowed. Use "issue" or "attempt".`);
        }

        tokenMatch = TOKEN_PATTERN.exec(template);
    }
}

export function renderStrictTemplate(template: string, context: PromptTemplateContext): string {
    validateStrictTemplate(template);

    TOKEN_PATTERN.lastIndex = 0;

    return template.replace(TOKEN_PATTERN, (_token, rawExpression) => {
        const expression = String(rawExpression).trim();
        const segments = expression.split('.');
        const [root, ...pathSegments] = segments;

        if (root === 'attempt') {
            if (pathSegments.length > 0) {
                throw new Error('Template value "attempt" does not support nested paths.');
            }

            return formatTemplateValue(context.attempt);
        }

        if (root === 'issue') {
            return formatTemplateValue(resolvePath(context.issue, pathSegments));
        }

        throw new Error(`Unsupported template root "${root}".`);
    });
}
