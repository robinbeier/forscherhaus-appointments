const {execSync} = require('node:child_process');

const omit = (process.env.npm_config_omit || '')
    .split(/[,\s]+/)
    .map((value) => value.trim())
    .filter(Boolean);

const isProductionInstall =
    process.env.NODE_ENV === 'production' || process.env.npm_config_production === 'true' || omit.includes('dev');

if (isProductionInstall) {
    console.log('Skipping assets refresh for production/--omit=dev install.');
    process.exit(0);
}

execSync('npm run assets:refresh', {stdio: 'inherit'});
