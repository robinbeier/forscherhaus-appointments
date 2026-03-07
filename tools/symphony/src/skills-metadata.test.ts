import assert from 'node:assert/strict';
import {readdir, readFile} from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';
import {parse as parseYaml} from 'yaml';

const skillsRootPath = fileURLToPath(new URL('../../../.codex/skills', import.meta.url));
const FRONT_MATTER_PATTERN = /^---\s*\n([\s\S]*?)\n---\s*(?:\n|$)/;

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

        const frontMatter = parseYaml(frontMatterMatch[1]) as Record<string, unknown>;
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
