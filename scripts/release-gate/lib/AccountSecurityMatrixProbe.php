<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;
use Throwable;

/** Direct ROB-552 method and CSRF verification for one owned synthetic account. */
final class AccountSecurityMatrixProbe
{
    /** @var callable():GateHttpClient */
    private $clientFactory;

    /** @var callable(?string):void */
    private $rememberSession;

    /**
     * @param callable():GateHttpClient $clientFactory
     * @param callable(?string):void|null $rememberSession
     */
    public function __construct(
        callable $clientFactory,
        private readonly object $db,
        ?callable $rememberSession = null,
        private readonly string $csrfCookieName = 'csrf_cookie',
        private readonly string $csrfTokenName = 'csrf_token',
    ) {
        $this->clientFactory = $clientFactory;
        $this->rememberSession = $rememberSession ?? static function (?string $session): void {};
    }

    /**
     * @param array{user_id:int,username:string,password:string,email:string,marker:string} $context
     * @return array{status:string,coverage:string,method_statuses:array<string,int>,csrf_statuses:array<string,int>,valid_post_status:int,observed:string}
     */
    public function run(array $context, ?callable $observe = null): array
    {
        $this->assertContext($context);
        $observe ??= static function (string $phase, string $outcome): void {};
        $methodStatuses = [];
        $csrfStatuses = [];

        foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $isOptions = $method === 'OPTIONS';
            $methodStatuses[strtolower($method)] = $this->runCase(
                $context,
                'method_' . strtolower($method),
                $observe,
                function (GateHttpClient $client, array $payload) use ($method): GateHttpResponse {
                    if ($method === 'GET') {
                        return $client->get('account/save', ['account' => $payload]);
                    }

                    return $client->requestApp(
                        $method,
                        'account/save',
                        in_array($method, ['PUT', 'PATCH', 'DELETE'], true) ? ['account' => $payload] : [],
                    );
                },
                $isOptions ? 200 : 405,
                false,
                !$isOptions,
            );
        }

        $csrfStatuses['missing'] = $this->runCase(
            $context,
            'csrf_missing',
            $observe,
            static fn(GateHttpClient $client, array $payload): GateHttpResponse => $client->requestApp(
                'POST',
                'account/save',
                ['account' => $payload],
            ),
            403,
        );
        $csrfStatuses['invalid'] = $this->runCase(
            $context,
            'csrf_invalid',
            $observe,
            function (GateHttpClient $client, array $payload): GateHttpResponse {
                $invalid = str_repeat('0', 64);
                if ($client->getCookie($this->csrfCookieName) === $invalid) {
                    $invalid = str_repeat('f', 64);
                }

                return $client->requestApp('POST', 'account/save', [
                    'account' => $payload,
                    $this->csrfTokenName => $invalid,
                ]);
            },
            403,
        );
        $validPostStatus = $this->runCase(
            $context,
            'csrf_valid_post',
            $observe,
            static function (GateHttpClient $client, array $payload): GateHttpResponse {
                $payload['first_name'] = 'Defense Matrix Verified';

                return $client->requestApp('POST', 'account/save', ['account' => $payload], withCsrfToken: true);
            },
            200,
            true,
        );

        return [
            'status' => 'verified',
            'coverage' => 'complete',
            'method_statuses' => $methodStatuses,
            'csrf_statuses' => $csrfStatuses,
            'valid_post_status' => $validPostStatus,
            'observed' =>
                'Owned synthetic account kept every non-POST method and missing or invalid CSRF non-mutating; route-level methods were rejected, the global OPTIONS preflight remained inert, and one valid protected POST changed only the intended first name.',
        ];
    }

    /**
     * @param array{user_id:int,username:string,password:string,email:string,marker:string} $context
     * @param callable(string,string):void $observe
     * @param callable(GateHttpClient,array<string,mixed>):GateHttpResponse $request
     */
    private function runCase(
        array $context,
        string $phase,
        callable $observe,
        callable $request,
        int $expectedStatus,
        bool $expectFirstNameChange = false,
        bool $expectAllowPost = false,
    ): int {
        $client = ($this->clientFactory)();
        if (!$client instanceof GateHttpClient) {
            throw new RuntimeException('Account security matrix client factory returned an invalid client.');
        }

        $loggedIn = false;
        $caseError = null;
        $logoutError = null;
        $status = 0;
        $observe($phase, 'started');
        try {
            $loginPage = $client->get('login');
            $this->remember($client);
            $this->expectStatus($loginPage, 200, 'login page');
            $login = $client->post('login/validate', [
                'username' => $context['username'],
                'password' => $context['password'],
            ]);
            $this->remember($client);
            $this->expectStatus($login, 200, 'login validation');
            $loginData = json_decode($login->body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($loginData) || ($loginData['success'] ?? false) !== true) {
                throw new RuntimeException('Synthetic matrix login did not succeed.');
            }
            $loggedIn = true;

            $before = $this->snapshot($context);
            $payload = $before['user'];
            $payload['settings'] = [
                'username' => $before['settings']['username'],
                'notifications' => $before['settings']['notifications'],
                'calendar_view' => $before['settings']['calendar_view'],
            ];
            $response = $request($client, $payload);
            $this->remember($client);
            $this->expectStatus($response, $expectedStatus, $phase);
            if ($expectAllowPost && strtoupper((string) $response->header('allow')) !== 'POST') {
                throw new RuntimeException($phase . ' did not advertise Allow: POST.');
            }

            $after = $this->snapshot($context);
            if ($expectFirstNameChange) {
                if (($after['user']['first_name'] ?? null) !== 'Defense Matrix Verified') {
                    throw new RuntimeException('Valid protected POST did not persist the intended first name.');
                }
                $before['user']['first_name'] = $after['user']['first_name'];
                $before['user']['update_datetime'] = $after['user']['update_datetime'] ?? null;
            }
            if ($before !== $after) {
                throw new RuntimeException($phase . ' changed fields outside its allowed result.');
            }
            $status = $response->statusCode;
            $observe($phase, 'passed');
        } catch (Throwable $error) {
            $observe($phase, 'failed');
            $caseError = $error;
        } finally {
            if ($loggedIn) {
                try {
                    $logout = $client->get('logout');
                    $this->remember($client);
                    $this->expectStatus($logout, 200, 'synthetic matrix logout');
                    $afterLogout = $client->get('account');
                    $this->remember($client);
                    if ($afterLogout->statusCode !== 307) {
                        throw new RuntimeException('Synthetic matrix session remained authenticated after logout.');
                    }
                } catch (Throwable $error) {
                    $logoutError = $error;
                }
            }
        }

        if ($caseError !== null) {
            throw $caseError;
        }
        if ($logoutError !== null) {
            throw $logoutError;
        }

        return $status;
    }

    /** @param array{user_id:int,username:string,password:string,email:string,marker:string} $context */
    private function assertContext(array $context): void
    {
        if (
            (int) ($context['user_id'] ?? 0) < 1 ||
            (string) ($context['username'] ?? '') === '' ||
            (string) ($context['password'] ?? '') === '' ||
            (string) ($context['email'] ?? '') === '' ||
            (string) ($context['marker'] ?? '') === ''
        ) {
            throw new RuntimeException('Account security matrix requires one complete owned synthetic context.');
        }
    }

    /**
     * @param array{user_id:int,username:string,password:string,email:string,marker:string} $context
     * @return array{user:array<string,mixed>,settings:array<string,mixed>}
     */
    private function snapshot(array $context): array
    {
        $user = $this->db
            ->get_where('users', [
                'id' => $context['user_id'],
                'email' => $context['email'],
                'notes' => $context['marker'],
            ])
            ->row_array();
        $settings = $this->db
            ->get_where('user_settings', [
                'id_users' => $context['user_id'],
                'username' => $context['username'],
            ])
            ->row_array();
        if (!is_array($user) || $user === [] || !is_array($settings) || $settings === []) {
            throw new RuntimeException('Owned matrix account snapshot is incomplete.');
        }

        return ['user' => $user, 'settings' => $settings];
    }

    private function remember(GateHttpClient $client): void
    {
        ($this->rememberSession)($client->getCookie('ea_session'));
    }

    private function expectStatus(GateHttpResponse $response, int $expected, string $operation): void
    {
        if ($response->statusCode !== $expected) {
            throw new RuntimeException($operation . ' returned an unexpected HTTP status.');
        }
    }
}
