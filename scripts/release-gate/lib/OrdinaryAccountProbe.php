<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

/**
 * Exercises the ordinary authenticated account flow for one owned synthetic user.
 *
 * The probe deliberately covers the positive own-account path and the method
 * guard. It does not claim to be a complete authorization or CSRF matrix.
 */
final class OrdinaryAccountProbe
{
    public function __construct(
        private readonly GateHttpClient $client,
        private readonly object $db,
        ?callable $rememberSession = null,
    ) {
        $this->rememberSession = $rememberSession ?? static function (?string $session): void {};
    }

    /** @var callable(?string):void */
    private readonly mixed $rememberSession;

    /**
     * @param array{user_id:int,username:string,password:string,run_id:string,email:string,marker:string} $context
     * @return array{status:string,coverage:string,login_status:int,account_status:int,save_status:int,get_save_status:int,logout_status:int,post_logout_account_status:int,observed:string}
     */
    public function run(array $context, ?callable $observe = null): array
    {
        $userId = (int) ($context['user_id'] ?? 0);
        $username = (string) ($context['username'] ?? '');
        $password = (string) ($context['password'] ?? '');
        $email = (string) ($context['email'] ?? '');
        $marker = (string) ($context['marker'] ?? '');
        if ($userId < 1 || $username === '' || $password === '' || $email === '' || $marker === '') {
            throw new RuntimeException('Ordinary account probe requires one complete synthetic user context.');
        }

        $observe ??= static function (string $phase, string $outcome): void {};
        $phase = 'account_snapshot';
        $loggedIn = false;
        $logoutStatus = 0;
        $result = null;
        $cleanupError = null;
        try {
            $observe($phase, 'started');
            $before = $this->snapshot($userId, $username, $email, $marker);
            $observe($phase, 'passed');
            $phase = 'login_page';
            $observe($phase, 'started');
            $loginPage = $this->client->get('login');
            $this->remember();
            $this->expectStatus($loginPage, 200, 'login page');
            $observe($phase, 'passed');
            $phase = 'login_validate';
            $observe($phase, 'started');
            $login = $this->client->post('login/validate', [
                'username' => $username,
                'password' => $password,
            ]);
            $this->remember();
            $this->expectStatus($login, 200, 'login validation');
            $loginData = json_decode($login->body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($loginData) || ($loginData['success'] ?? false) !== true) {
                throw new RuntimeException('Synthetic account login did not succeed.');
            }
            $loggedIn = true;

            $observe($phase, 'passed');
            $phase = 'account_page';
            $observe($phase, 'started');
            $account = $this->client->get('account');
            $this->remember();
            $this->expectStatus($account, 200, 'authenticated account page');
            $this->assertAccountIdentity($account->body, $userId);

            $observe($phase, 'passed');
            $phase = 'get_save';
            $observe($phase, 'started');
            $getSave = $this->client->get('account/save');
            $this->remember();
            $this->expectStatus($getSave, 405, 'account/save GET guard');
            if (strtoupper((string) $getSave->header('allow')) !== 'POST') {
                throw new RuntimeException('account/save GET did not advertise Allow: POST.');
            }
            $afterGet = $this->snapshot($userId, $username, $email, $marker);
            $this->assertSnapshotEqual($before, $afterGet, 'account/save GET changed the synthetic account.');

            $observe($phase, 'passed');
            $phase = 'post_save';
            $observe($phase, 'started');
            $payload = $before['user'];
            $payload['first_name'] = 'Synthetic Updated';
            $payload['settings'] = [
                'username' => $before['settings']['username'],
                'notifications' => $before['settings']['notifications'],
                'calendar_view' => $before['settings']['calendar_view'],
            ];
            $save = $this->client->post('account/save', ['account' => $payload]);
            $this->remember();
            $this->expectStatus($save, 200, 'account/save POST');
            $after = $this->snapshot($userId, $username, $email, $marker);
            if (($after['user']['first_name'] ?? null) !== 'Synthetic Updated') {
                throw new RuntimeException('account/save did not persist the synthetic first name.');
            }
            $this->assertSnapshotEqualExceptFirstName($before, $after);

            $observe($phase, 'passed');
            $result = [
                'status' => 'verified',
                'coverage' => 'partial',
                'login_status' => $login->statusCode,
                'account_status' => $account->statusCode,
                'save_status' => $save->statusCode,
                'get_save_status' => $getSave->statusCode,
                'logout_status' => $logoutStatus,
                'post_logout_account_status' => 0,
                'observed' =>
                    'Ordinary synthetic own-account login, GET guard, and protected POST persistence succeeded; other methods and CSRF matrix remain outside this probe.',
            ];
        } catch (\Throwable $error) {
            $observe($phase, 'failed');
            throw $error;
        } finally {
            if ($loggedIn) {
                try {
                    $phase = 'logout';
                    $observe($phase, 'started');
                    $logout = $this->client->get('logout');
                    $this->remember();
                    $logoutStatus = $logout->statusCode;
                    $this->expectStatus($logout, 200, 'synthetic logout');
                    if (is_array($result)) {
                        $result['logout_status'] = $logoutStatus;
                    }
                    $observe($phase, 'passed');
                    $phase = 'post_logout';
                    $observe($phase, 'started');
                    $afterLogout = $this->client->get('account');
                    $this->remember();
                    if (is_array($result)) {
                        $result['post_logout_account_status'] = $afterLogout->statusCode;
                    }
                    if ($afterLogout->statusCode !== 307) {
                        throw new RuntimeException('Authenticated account remained accessible after synthetic logout.');
                    }
                    $observe($phase, 'passed');
                } catch (\Throwable $error) {
                    $observe($phase, 'failed');
                    $cleanupError = $error;
                }
            }
        }
        if ($cleanupError !== null) {
            throw $cleanupError;
        }
        if (!is_array($result)) {
            throw new RuntimeException('Ordinary account probe did not produce a result.');
        }
        return $result;
    }

    private function remember(): void
    {
        ($this->rememberSession)($this->client->getCookie('ea_session'));
    }

    /** @return array{user:array<string,mixed>,settings:array<string,mixed>} */
    private function snapshot(int $userId, string $username, string $email, string $marker): array
    {
        $user = $this->db->get_where('users', ['id' => $userId, 'email' => $email, 'notes' => $marker])->row_array();
        $settings = $this->db
            ->get_where('user_settings', ['id_users' => $userId, 'username' => $username])
            ->row_array();
        if (!is_array($user) || $user === [] || !is_array($settings) || $settings === []) {
            throw new RuntimeException('Synthetic account snapshot is incomplete.');
        }
        return ['user' => $user, 'settings' => $settings];
    }

    private function assertAccountIdentity(string $body, int $userId): void
    {
        if (preg_match('/const vars = (\{.*?\});/s', $body, $matches) !== 1) {
            throw new RuntimeException('Authenticated account identity was not observable.');
        }
        $vars = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($vars) || !isset($vars['account']['id']) || (int) $vars['account']['id'] !== $userId) {
            throw new RuntimeException('Authenticated account page exposed a different user identity.');
        }
    }

    private function expectStatus(GateHttpResponse $response, int $expected, string $operation): void
    {
        if ($response->statusCode !== $expected) {
            throw new RuntimeException(
                $operation . ' returned HTTP ' . $response->statusCode . ', expected ' . $expected . '.',
            );
        }
    }

    /** @param array{user:array<string,mixed>,settings:array<string,mixed>} $before */
    private function assertSnapshotEqual(array $before, array $after, string $message): void
    {
        if ($before !== $after) {
            throw new RuntimeException($message);
        }
    }

    /** @param array{user:array<string,mixed>,settings:array<string,mixed>} $before */
    private function assertSnapshotEqualExceptFirstName(array $before, array $after): void
    {
        $before['user']['first_name'] = $after['user']['first_name'];
        $before['user']['update_datetime'] = $after['user']['update_datetime'] ?? null;
        if ($before !== $after) {
            throw new RuntimeException('account/save changed fields beyond the synthetic first name.');
        }
    }
}
