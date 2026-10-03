<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;
use Throwable;

/** Proves that one existing admin session loses the classic admin read surface after demotion. */
final class BackofficeRoleRevocationProbe
{
    public function __construct(
        private readonly GateHttpClient $client,
        private readonly OrdinaryLiveFixture $fixture,
        ?callable $rememberSession = null,
    ) {
        $this->rememberSession = $rememberSession ?? static function (?string $session): void {};
    }

    /** @var callable(?string):void */
    private readonly mixed $rememberSession;

    /**
     * @return array{status:string,coverage:string,before_statuses:array<string,int>,after_statuses:array<string,int>,session_status:int,logout_status:int,observed:string}
     */
    public function run(): array
    {
        $state = $this->fixture->read();
        if (($state['role_slug'] ?? null) !== 'admin') {
            throw new RuntimeException('Role-revocation probe requires an ordinary admin fixture.');
        }

        $db = &\get_instance()->db;
        $beforeUser = $db->get_where('users', ['id' => (int) $state['user_id']])->row_array();
        $beforeSettings = $db->get_where('user_settings', ['id_users' => (int) $state['user_id']])->row_array();
        if ($beforeUser === [] || $beforeSettings === []) {
            throw new RuntimeException('Owned ordinary admin rows are missing.');
        }

        $loggedIn = false;
        $logoutAttempted = false;
        try {
            $loginPage = $this->client->get('login');
            $this->remember();
            $this->expect($loginPage, 200, 'login page');
            $login = $this->client->post('login/validate', [
                'username' => (string) $state['username'],
                'password' => (string) $state['password'],
            ]);
            $this->remember();
            $this->expect($login, 200, 'admin login');
            $payload = json_decode($login->body, true);
            if (!is_array($payload) || ($payload['success'] ?? false) !== true) {
                throw new RuntimeException('Ordinary admin login did not succeed.');
            }
            $loggedIn = true;

            $before = $this->readRoutes((string) $state['marker'], (int) $state['user_id'], 200);
            $this->fixture->transitionAdminToCustomer();
            $afterUser = $db->get_where('users', ['id' => (int) $state['user_id']])->row_array();
            $afterSettings = $db->get_where('user_settings', ['id_users' => (int) $state['user_id']])->row_array();
            $customerRole = $db->get_where('roles', ['slug' => 'customer'])->row_array();
            if ((int) ($afterUser['id_roles'] ?? 0) !== (int) ($customerRole['id'] ?? 0)) {
                throw new RuntimeException('Admin fixture did not transition to customer.');
            }
            $changed = array_diff_assoc($afterUser, $beforeUser);
            unset($changed['id_roles']);
            if ($changed !== [] || $afterSettings !== $beforeSettings) {
                throw new RuntimeException('Role transition changed owned user fields beyond id_roles or settings.');
            }
            $after = $this->readRoutes((string) $state['marker'], (int) $state['user_id'], 403);
            $sessionCheck = $this->client->get('login');
            $this->remember();
            $this->expect($sessionCheck, 403, 'same-session customer calendar after role transition');
            if (
                preg_match('~/(?:index\.php/)?calendar/?$~', (string) parse_url($sessionCheck->url, PHP_URL_PATH)) !== 1
            ) {
                throw new RuntimeException(
                    'Login did not redirect the authenticated session to its customer-denied calendar.',
                );
            }
            if (
                $db->get_where('users', ['id' => (int) $state['user_id']])->row_array() !== $afterUser ||
                $db->get_where('user_settings', ['id_users' => (int) $state['user_id']])->row_array() !== $afterSettings
            ) {
                throw new RuntimeException('Denied Backoffice reads changed the owned synthetic account.');
            }
            $logoutAttempted = true;
            $logoutStatus = $this->logout();

            return [
                'status' => 'verified',
                'coverage' => 'bounded_admin_reads',
                'before_statuses' => $before,
                'after_statuses' => $after,
                'session_status' => $sessionCheck->statusCode,
                'logout_status' => $logoutStatus,
                'observed' =>
                    'The same authenticated session received 200 for owned admin index, search, find, and direct search-alias reads before transition and 403 for each after the stored role changed. Login still redirected the session to the customer-denied calendar; only id_roles changed on the owned user row and user settings stayed unchanged.',
            ];
        } catch (Throwable $error) {
            if ($loggedIn && !$logoutAttempted) {
                try {
                    $this->logout();
                } catch (Throwable) {
                    // Preserve the probe failure; fixture cleanup remains the caller's responsibility.
                }
            }
            throw $error;
        }
    }

    /** @return array<string,int> */
    private function readRoutes(string $marker, int $adminId, int $expected): array
    {
        $requests = [
            'GET admins' => fn(): GateHttpResponse => $this->client->get('admins'),
            'GET admins/index' => fn(): GateHttpResponse => $this->client->get('admins/index'),
            'POST admins/search' => fn(): GateHttpResponse => $this->client->post('admins/search', [
                'keyword' => $marker,
            ]),
            'GET admins/search' => fn(): GateHttpResponse => $this->client->get('admins/search', [
                'keyword' => $marker,
            ]),
            'GET alias' => fn(): GateHttpResponse => $this->client->get('backend_api/ajax_filter_admins', [
                'keyword' => $marker,
            ]),
            'POST admins/find' => fn(): GateHttpResponse => $this->client->post('admins/find', [
                'admin_id' => $adminId,
            ]),
            'GET admins/find' => fn(): GateHttpResponse => $this->client->get('admins/find', ['admin_id' => $adminId]),
        ];
        $statuses = [];
        foreach ($requests as $name => $request) {
            $response = $request();
            $this->remember();
            $this->expect($response, $expected, $name);
            $statuses[$name] = $response->statusCode;
        }
        return $statuses;
    }

    private function logout(): int
    {
        $response = $this->client->get('logout');
        $this->remember();
        $this->expect($response, 200, 'logout');
        return $response->statusCode;
    }

    private function remember(): void
    {
        ($this->rememberSession)($this->client->getCookie('ea_session'));
    }

    private function expect(GateHttpResponse $response, int $status, string $operation): void
    {
        if ($response->statusCode !== $status) {
            throw new RuntimeException(
                $operation . ' returned HTTP ' . $response->statusCode . ', expected ' . $status . '.',
            );
        }
    }
}
