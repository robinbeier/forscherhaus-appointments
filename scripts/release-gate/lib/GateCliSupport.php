<?php

declare(strict_types=1);

namespace ReleaseGate;

final class GateCliSupport
{
    public const EXIT_ASSERTION_FAILURE = 1;
    public const EXIT_RUNTIME_ERROR = 2;

    /**
     * Map assertion failures to either behavioral regression (1) or runtime/config (2).
     *
     * @param array<int, array<string, mixed>> $checks
     */
    public static function classifyAssertionExitCode(array $checks): int
    {
        $lastCheck = end($checks);

        if (!is_array($lastCheck) || !isset($lastCheck['name'])) {
            return self::EXIT_ASSERTION_FAILURE;
        }

        if (self::isHttpStatusAssertionFailure($lastCheck)) {
            return self::EXIT_RUNTIME_ERROR;
        }

        $checkName = (string) $lastCheck['name'];
        if (self::isRuntimePreflightCheck($checkName)) {
            return self::EXIT_RUNTIME_ERROR;
        }

        return self::EXIT_ASSERTION_FAILURE;
    }

    /**
     * Read CSRF token/cookie names from CodeIgniter config with safe defaults.
     *
     * @return array{csrf_token_name:string,csrf_cookie_name:string}
     */
    public static function resolveCsrfNamesFromConfig(string $configPath): array
    {
        $defaults = [
            'csrf_token_name' => 'csrf_token',
            'csrf_cookie_name' => 'csrf_cookie',
        ];
        $cookiePrefix = '';

        if (!is_file($configPath) || !is_readable($configPath)) {
            return $defaults;
        }

        $configContent = file_get_contents($configPath);
        if (!is_string($configContent) || $configContent === '') {
            return $defaults;
        }

        $lookupKeys = array_merge(array_keys($defaults), ['cookie_prefix']);

        foreach ($lookupKeys as $key) {
            $pattern = '/^\s*\$config\[\'' . preg_quote($key, '/') . '\'\]\s*=\s*([\'"])(.*?)\1\s*;/m';
            if (preg_match($pattern, $configContent, $matches) !== 1) {
                continue;
            }

            $resolvedValue = trim((string) ($matches[2] ?? ''));
            if ($resolvedValue === '') {
                continue;
            }

            if ($key === 'cookie_prefix') {
                $cookiePrefix = $resolvedValue;
                continue;
            }

            $defaults[$key] = $resolvedValue;
        }

        if ($cookiePrefix !== '') {
            $defaults['csrf_cookie_name'] = $cookiePrefix . $defaults['csrf_cookie_name'];
        }

        return $defaults;
    }

    /**
     * Resolve a CLI password without placing stdin bytes in an argv string.
     * Explicit --password remains supported for existing local callers.
     *
     * @param array<string,mixed> $options
     */
    public static function readPassword(array $options): string
    {
        $hasExplicit = array_key_exists('password', $options);
        $hasStdin = array_key_exists('password-stdin', $options);

        if ($hasExplicit && $hasStdin) {
            throw new \InvalidArgumentException('Use either --password or --password-stdin, not both.');
        }

        if ($hasStdin) {
            $password = stream_get_contents(STDIN);
            if (!is_string($password) || $password === '') {
                throw new \InvalidArgumentException('--password-stdin requires non-empty stdin input.');
            }

            return $password;
        }

        if (!$hasExplicit) {
            throw new \InvalidArgumentException('Missing required option --password or --password-stdin.');
        }

        $password = is_array($options['password']) ? (string) end($options['password']) : (string) $options['password'];
        if ($password === '') {
            throw new \InvalidArgumentException('Password must not be empty.');
        }

        return $password;
    }

    /**
     * @param array<string, mixed> $check
     */
    private static function isHttpStatusAssertionFailure(array $check): bool
    {
        $error = (string) ($check['error'] ?? '');

        return preg_match('/expected HTTP .* got \d+\./i', $error) === 1;
    }

    private static function isRuntimePreflightCheck(string $checkName): bool
    {
        $runtimeChecks = ['readiness_login_page', 'readiness_pdf_health', 'auth_login_validate'];

        return in_array($checkName, $runtimeChecks, true);
    }
}
