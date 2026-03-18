<?php

declare(strict_types=1);

/**
 * @param list<string> $arguments
 * @return list<string>
 */
function appendConfiguredPlaywrightBrowserArgument(array $arguments): array
{
    if (($arguments[0] ?? null) !== 'open') {
        return $arguments;
    }

    foreach ($arguments as $argument) {
        if (str_starts_with((string) $argument, '--browser=')) {
            return $arguments;
        }
    }

    $arguments[] = '--browser=' . resolveConfiguredPlaywrightBrowser();

    return $arguments;
}

/**
 * @param list<string> $arguments
 * @return list<string>
 */
function prepareConfiguredPlaywrightCommandArguments(array $arguments, bool $headed = false): array
{
    if (($arguments[0] ?? null) !== 'open') {
        return $arguments;
    }

    if ($headed && !in_array('--headed', $arguments, true)) {
        $arguments[] = '--headed';
    }

    return appendConfiguredPlaywrightBrowserArgument($arguments);
}

/**
 * @return list<string>
 */
function buildPlaywrightSessionArguments(string $sessionId): array
{
    return ['-s=' . $sessionId];
}

function resolveConfiguredPlaywrightBrowser(): string
{
    $configuredBrowser = trim((string) getenv('PLAYWRIGHT_MCP_BROWSER'));

    return $configuredBrowser !== '' ? $configuredBrowser : 'firefox';
}
