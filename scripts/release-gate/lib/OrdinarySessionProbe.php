<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

/** Normal elapsed inactivity on one private synthetic identity; no clock/session editing. */
final class OrdinarySessionProbe
{
    public function __construct(
        private readonly GateHttpClient $client,
        private readonly OrdinaryProbeSessions $sessions,
        private readonly string $baseUrl,
        private readonly int $expiration,
    ) {
        $url = parse_url($baseUrl);
        if (
            !is_array($url) ||
            ($url['scheme'] ?? '') !== 'http' ||
            !in_array($url['host'] ?? '', ['localhost', '127.0.0.1'], true) ||
            isset($url['user']) ||
            isset($url['pass']) ||
            isset($url['query']) ||
            isset($url['fragment']) ||
            !in_array($url['path'] ?? '', ['', '/'], true) ||
            $expiration < 1 ||
            $expiration > 7200
        ) {
            throw new RuntimeException('Session probe requires a bounded local application endpoint and expiration.');
        }
    }

    /** The caller must own the live fixture and an independent cleanup deadline. */
    public function run(array $context, ?callable $progress = null, ?callable $assertActive = null): array
    {
        $assertActive ??= static fn() => null;
        if (($context['expires_at'] ?? 0) < time() + $this->expiration + 120) {
            throw new RuntimeException('Fixture deadline cannot cover ordinary session expiration.');
        }
        $cookie = null;
        try {
            $assertActive();
            $page = $this->client->get('login');
            $this->remember();
            if ($page->statusCode !== 200) {
                throw new RuntimeException('Ordinary login page unavailable.');
            }
            $assertActive();
            $login = $this->client->post('login/validate', [
                'username' => $context['username'],
                'password' => $context['password'],
            ]);
            $this->remember();
            if ($login->statusCode !== 200 || (json_decode($login->body, true)['success'] ?? false) !== true) {
                throw new RuntimeException('Ordinary synthetic login failed.');
            }
            $assertActive();
            $account = $this->client->get('account');
            $this->remember();
            if ($account->statusCode !== 200) {
                throw new RuntimeException('Fresh own account access failed.');
            }
            $cookie = $this->client->getCookie('ea_session');
            if (!$cookie) {
                throw new RuntimeException('Own authenticated session cookie missing.');
            }
            $activity = $this->sessions->ownActivity($cookie, $context);
            if (abs(time() - $activity) > 10) {
                throw new RuntimeException('Fresh session activity was not observed.');
            }
            $start = hrtime(true);
            $waitSeconds = $this->expiration + 2;
            if ($progress !== null) {
                $progress(['phase' => 'waiting', 'expiration_seconds' => $this->expiration]);
            }
            // No HTTP requests or session writes during the ordinary inactivity interval.
            while ((hrtime(true) - $start) / 1e9 < $waitSeconds) {
                $assertActive();
                if (time() >= (int) $context['expires_at'] - 60) {
                    throw new RuntimeException('Fixture cleanup deadline reached before evidence completed.');
                }
                usleep((int) (max(0.0, min(1.0, $waitSeconds - (hrtime(true) - $start) / 1e9)) * 1000000));
            }
            $assertActive();
            // File and unchanged authenticated activity must still exist before expired request.
            if (
                $this->sessions->ownActivity($cookie, $context) !== $activity ||
                time() - $activity < $this->expiration
            ) {
                throw new RuntimeException('Inactivity/file-preservation precondition was not established.');
            }
            $expired = $this->ownCookieGet('account', $cookie);
            $assertActive();
            if ($expired['status'] !== 307 || !preg_match('~/(?:index\.php/)?login(?:\?|$)~', $expired['location'])) {
                throw new RuntimeException('Expired own session was not redirected to login.');
            }
            if ($expired['cookie'] === null || $expired['cookie'] === $cookie) {
                throw new RuntimeException('Expired own session did not rotate.');
            }
            $cookie = $expired['cookie'];
            return [
                'status' => 'verified',
                'coverage' => 'complete',
                'expiration_seconds' => $this->expiration,
                'elapsed_seconds' => (int) ((hrtime(true) - $start) / 1e9),
                'fresh_account' => true,
                'file_present_before_expired_request' => true,
                'activity_unchanged_while_idle' => true,
                'expired_redirect' => true,
                'rotated' => true,
                'method' =>
                    'Ordinary elapsed inactivity with original own cookie retained; no session or clock editing.',
            ];
        } finally {
            if ($cookie !== null) {
                $logout = $this->ownCookieGet('logout', $cookie);
                if ($logout['status'] !== 200) {
                    throw new RuntimeException('Session probe logout failed; explicit cleanup required.');
                }
            } else {
                $this->client->get('logout');
                $this->remember();
            }
        }
    }

    private function remember(): void
    {
        $this->sessions->remember($this->client->getCookie('ea_session'));
    }

    /** Fixed normal read/logout routes and only this probe's already recorded cookie. */
    private function ownCookieGet(string $route, string $cookie): array
    {
        if (!in_array($route, ['account', 'logout'], true) || !preg_match('/\A[a-zA-Z0-9,-]{22,256}\z/D', $cookie)) {
            throw new RuntimeException('Invalid ordinary session request.');
        }
        $headers = [];
        $curl = curl_init(rtrim($this->baseUrl, '/') . '/index.php/' . $route);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_COOKIE => 'ea_session=' . $cookie,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $parts = explode(':', trim($line), 2);
                if (count($parts) === 2) {
                    $headers[strtolower($parts[0])][] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        try {
            $body = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($body === false) {
                throw new RuntimeException('Own session HTTP request failed.');
            }
        } finally {
            curl_close($curl);
        }
        $newCookie = null;
        foreach ($headers['set-cookie'] ?? [] as $header) {
            if (preg_match('/\Aea_session=([a-zA-Z0-9,-]{22,256});/', $header, $match)) {
                $newCookie = $match[1];
                $this->sessions->remember($newCookie);
            }
        }
        return ['status' => $status, 'location' => $headers['location'][0] ?? '', 'cookie' => $newCookie];
    }
}
