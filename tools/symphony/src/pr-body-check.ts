import {readFile} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const HEADING_PATTERN = /^(##\s+.+)$/gm;
const COMMENT_PATTERN = /<!--[\s\S]*?-->/m;
const DEFAULT_TEMPLATE_PATH = fileURLToPath(new URL('../../../.github/pull_request_template.md', import.meta.url));

export class PrBodyCheckError extends Error {
    public constructor(message: string) {
        super(message);
        this.name = 'PrBodyCheckError';
    }
}

export function extractRequiredHeadings(template: string): string[] {
    return Array.from(template.matchAll(HEADING_PATTERN), (match) => match[1].trim());
}

function extractBodyHeadings(body: string): string[] {
    return Array.from(body.matchAll(HEADING_PATTERN), (match) => match[1].trim());
}

function sectionContentForHeading(body: string, heading: string, requiredHeadings: string[]): string {
    const lines = body.split(/\r?\n/);
    const headingLineIndex = lines.findIndex((line) => line.trim() === heading);
    if (headingLineIndex < 0) {
        return '';
    }

    let nextHeadingLineIndex = lines.length;
    for (let index = headingLineIndex + 1; index < lines.length; index += 1) {
        if (requiredHeadings.includes(lines[index].trim())) {
            nextHeadingLineIndex = index;
            break;
        }
    }

    return lines
        .slice(headingLineIndex + 1, nextHeadingLineIndex)
        .join('\n')
        .trim();
}

export function validatePrBody(args: {template: string; body: string}): void {
    const requiredHeadings = extractRequiredHeadings(args.template);
    if (requiredHeadings.length === 0) {
        throw new PrBodyCheckError('PR template does not define any required headings.');
    }

    if (COMMENT_PATTERN.test(args.body)) {
        throw new PrBodyCheckError('PR description still contains template placeholder comments.');
    }

    const bodyHeadings = extractBodyHeadings(args.body);
    for (const heading of requiredHeadings) {
        if (!bodyHeadings.includes(heading)) {
            throw new PrBodyCheckError(`Missing required heading: ${heading}`);
        }
    }

    const headingOrder = requiredHeadings.map((heading) => bodyHeadings.indexOf(heading));
    for (let index = 1; index < headingOrder.length; index += 1) {
        if (headingOrder[index] <= headingOrder[index - 1]) {
            throw new PrBodyCheckError('Required headings are out of order.');
        }
    }

    for (const heading of requiredHeadings) {
        if (sectionContentForHeading(args.body, heading, requiredHeadings).length === 0) {
            throw new PrBodyCheckError(`Section cannot be empty: ${heading}`);
        }
    }
}

interface ParsedCliArgs {
    filePath: string;
    templatePath: string;
}

function parseCliArgs(argv: string[]): ParsedCliArgs {
    const args = [...argv];
    if (args[0] === 'lint') {
        args.shift();
    }

    let filePath = '';
    let templatePath = DEFAULT_TEMPLATE_PATH;

    for (let index = 0; index < args.length; index += 1) {
        const argument = args[index];
        if (argument === '--file') {
            filePath = args[index + 1] ?? '';
            index += 1;
            continue;
        }

        if (argument === '--template') {
            templatePath = args[index + 1] ?? '';
            index += 1;
            continue;
        }

        if (argument === '--help' || argument === '-h') {
            throw new PrBodyCheckError(
                'Usage: tsx src/pr-body-check.ts [lint] --file <body.md> [--template <template.md>]',
            );
        }

        throw new PrBodyCheckError(`Unknown argument: ${argument}`);
    }

    if (filePath.trim().length === 0) {
        throw new PrBodyCheckError('Missing required argument: --file <body.md>');
    }

    return {
        filePath: path.resolve(filePath),
        templatePath: path.resolve(templatePath),
    };
}

export async function runPrBodyCheckCli(argv: string[]): Promise<void> {
    const parsedArgs = parseCliArgs(argv);
    const [template, body] = await Promise.all([
        readFile(parsedArgs.templatePath, 'utf8'),
        readFile(parsedArgs.filePath, 'utf8'),
    ]);

    validatePrBody({
        template,
        body,
    });

    process.stdout.write('PR body format valid.\n');
}

const entrypointPath = process.argv[1] ? path.resolve(process.argv[1]) : '';
if (entrypointPath === fileURLToPath(import.meta.url)) {
    await runPrBodyCheckCli(process.argv.slice(2)).catch((error) => {
        const message = error instanceof Error ? error.message : String(error);
        process.stderr.write(`${message}\n`);
        process.exitCode = 1;
    });
}
