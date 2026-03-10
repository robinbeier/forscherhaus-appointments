const js = require('@eslint/js');
const {FlatCompat} = require('@eslint/eslintrc');
const legacyConfig = require('./.eslintrc.cjs');

const compat = new FlatCompat({
    baseDirectory: __dirname,
    recommendedConfig: js.configs.recommended,
    allConfig: js.configs.all,
});

const {ignorePatterns = [], ...legacyCompatConfig} = legacyConfig;

module.exports = [
    {
        ignores: ignorePatterns,
    },
    ...compat.config(legacyCompatConfig),
];
