import assert from 'node:assert/strict';
import {access, readdir, readFile} from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';
import {parse as parseYaml} from 'yaml';

const skillsRootPath = fileURLToPath(new URL('../../../.codex/skills', import.meta.url));
const FRONT_MATTER_PATTERN = /^---\s*\n([\s\S]*?)\n---\s*(?:\n|$)/;

type SkillFrontMatter = {
    name?: unknown;
    description?: unknown;
};

type SkillOpenAiMetadata = {
    interface?: {
        display_name?: unknown;
        short_description?: unknown;
        default_prompt?: unknown;
    };
    policy?: {
        allow_implicit_invocation?: unknown;
    };
    dependencies?: {
        tools?: Array<{
            type?: unknown;
            value?: unknown;
            description?: unknown;
            transport?: unknown;
            url?: unknown;
        }>;
    };
};

test('repo-local Symphony skills expose valid front matter', async () => {
    const entries = await readdir(skillsRootPath, {withFileTypes: true});
    const skillDirectories = entries
        .filter((entry) => entry.isDirectory())
        .map((entry) => entry.name)
        .sort();

    assert.ok(skillDirectories.includes('land'));
    assert.ok(skillDirectories.includes('napkin'));

    for (const directoryName of skillDirectories) {
        const skillPath = path.join(skillsRootPath, directoryName, 'SKILL.md');
        const contents = await readFile(skillPath, 'utf8');
        const frontMatterMatch = contents.match(FRONT_MATTER_PATTERN);
        assert.ok(frontMatterMatch, `Missing YAML front matter in ${directoryName}/SKILL.md`);

        const frontMatter = parseYaml(frontMatterMatch[1]) as SkillFrontMatter;
        assert.equal(typeof frontMatter.name, 'string', `${directoryName}/SKILL.md must define a string name`);
        assert.equal(
            typeof frontMatter.description,
            'string',
            `${directoryName}/SKILL.md must define a string description`,
        );
        assert.ok(String(frontMatter.name).trim().length > 0, `${directoryName}/SKILL.md name cannot be empty`);
        assert.ok(
            String(frontMatter.description).trim().length > 0,
            `${directoryName}/SKILL.md description cannot be empty`,
        );
    }
});

test('selected repo-local Symphony skills expose valid optional OpenAI metadata', async () => {
    const metadataRequiredSkills = new Map<string, {implicitInvocation?: boolean; requiresLinearMcp?: boolean}>([
        ['babysit-pr', {implicitInvocation: false}],
        ['land', {implicitInvocation: false, requiresLinearMcp: true}],
        ['push', {implicitInvocation: false, requiresLinearMcp: true}],
        ['linear', {requiresLinearMcp: true}],
    ]);

    for (const [directoryName, expectations] of metadataRequiredSkills) {
        const metadataPath = path.join(skillsRootPath, directoryName, 'agents', 'openai.yaml');
        await access(metadataPath);

        const contents = await readFile(metadataPath, 'utf8');
        const metadata = parseYaml(contents) as SkillOpenAiMetadata;

        assert.equal(
            typeof metadata.interface?.display_name,
            'string',
            `${directoryName}/agents/openai.yaml must define interface.display_name`,
        );
        assert.equal(
            typeof metadata.interface?.short_description,
            'string',
            `${directoryName}/agents/openai.yaml must define interface.short_description`,
        );
        assert.equal(
            typeof metadata.interface?.default_prompt,
            'string',
            `${directoryName}/agents/openai.yaml must define interface.default_prompt`,
        );

        if (Object.hasOwn(expectations, 'implicitInvocation')) {
            assert.equal(
                metadata.policy?.allow_implicit_invocation,
                expectations.implicitInvocation,
                `${directoryName}/agents/openai.yaml must set policy.allow_implicit_invocation=${String(expectations.implicitInvocation)}`,
            );
        }

        if (expectations.requiresLinearMcp) {
            const toolDependencies = metadata.dependencies?.tools ?? [];
            assert.ok(
                toolDependencies.some(
                    (tool) =>
                        tool.type === 'mcp' &&
                        tool.value === 'linear' &&
                        typeof tool.description === 'string' &&
                        tool.description.trim().length > 0,
                ),
                `${directoryName}/agents/openai.yaml must declare the Linear MCP dependency`,
            );
        }
    }
});

test('any optional repo-local OpenAI metadata file remains structurally valid', async () => {
    const entries = await readdir(skillsRootPath, {withFileTypes: true});
    const skillDirectories = entries
        .filter((entry) => entry.isDirectory())
        .map((entry) => entry.name)
        .sort();

    for (const directoryName of skillDirectories) {
        const metadataPath = path.join(skillsRootPath, directoryName, 'agents', 'openai.yaml');

        try {
            await access(metadataPath);
        } catch {
            continue;
        }

        const contents = await readFile(metadataPath, 'utf8');
        const metadata = parseYaml(contents) as SkillOpenAiMetadata;
        assert.equal(typeof metadata, 'object', `${directoryName}/agents/openai.yaml must parse to an object`);
        assert.ok(metadata !== null, `${directoryName}/agents/openai.yaml cannot be null`);

        if (metadata.interface !== undefined) {
            assert.equal(
                typeof metadata.interface,
                'object',
                `${directoryName}/agents/openai.yaml interface must be a mapping when present`,
            );
        }

        if (metadata.policy?.allow_implicit_invocation !== undefined) {
            assert.equal(
                typeof metadata.policy.allow_implicit_invocation,
                'boolean',
                `${directoryName}/agents/openai.yaml policy.allow_implicit_invocation must be boolean`,
            );
        }

        for (const tool of metadata.dependencies?.tools ?? []) {
            assert.equal(typeof tool.type, 'string', `${directoryName}/agents/openai.yaml tool.type must be a string`);
            assert.equal(
                typeof tool.value,
                'string',
                `${directoryName}/agents/openai.yaml tool.value must be a string`,
            );

            if (tool.description !== undefined) {
                assert.equal(
                    typeof tool.description,
                    'string',
                    `${directoryName}/agents/openai.yaml tool.description must be a string when present`,
                );
            }

            if (tool.transport !== undefined) {
                assert.equal(
                    typeof tool.transport,
                    'string',
                    `${directoryName}/agents/openai.yaml tool.transport must be a string when present`,
                );
            }

            if (tool.url !== undefined) {
                assert.equal(
                    typeof tool.url,
                    'string',
                    `${directoryName}/agents/openai.yaml tool.url must be a string when present`,
                );
            }
        }
    }
});
