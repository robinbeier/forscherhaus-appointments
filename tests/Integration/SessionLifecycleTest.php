<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once __DIR__ . '/Support/DefenseCycleFixtures.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class SessionLifecycleTest extends TestCase
{
    public function testRealHttpSessionExpiresWhileItsFileStillExists(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Requires scripts/ci/run_defense_cycle.sh; never changes production expiration.');
        }
        $fixture = new DefenseCycleFixtures();
        $server = null;
        try {
            $fixture->create();
            $server = new DefenseCycleHttpServer(5);
            $client = $server->client();
            self::assertSame(200, $client->get('login')->statusCode);
            $login = $client->post('login/validate', [
                'username' => $fixture->run . '_actor',
                'password' => $fixture->password,
            ]);
            self::assertSame(200, $login->statusCode);
            self::assertTrue(json_decode($login->body, true, 512, JSON_THROW_ON_ERROR)['success']);
            self::assertSame(200, $client->get('account')->statusCode);
            $session = $client->getCookie('ea_session');
            self::assertNotEmpty($session);
            $file = $server->directory . '/sessions/ea_session' . $session;
            self::assertFileExists($file);
            // Ordinary elapsed inactivity; no editing session state or forcing a controller method.
            sleep(6);
            clearstatcache(true, $file);
            self::assertFileExists($file, 'Expiry must be observed before any file cleanup.');
            $curl = curl_init($server->baseUrl . '/index.php/account');
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 10,
                // Retain our own cookie so client expiry cannot masquerade as server enforcement.
                CURLOPT_COOKIE => 'ea_session=' . $session,
            ]);
            $response = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            self::assertIsString($response);
            self::assertSame(307, $status);
            self::assertMatchesRegularExpression('~Location: .*index.php/login~i', $response);
            self::assertSame(1, preg_match('/Set-Cookie: ea_session=([^;]+)/i', $response, $match));
            self::assertNotSame($session, $match[1], 'Expired authenticated session must rotate.');
        } finally {
            try {
                $server?->close();
            } finally {
                $fixture->cleanup();
            }
        }
    }
}
