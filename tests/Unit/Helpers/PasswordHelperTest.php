<?php

namespace Tests\Unit\Helpers;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PasswordHelperTest extends TestCase
{
    public function testNewHashesUseArgon2idAndVerifyAcrossTheFullPasswordLimit(): void
    {
        $password = str_repeat('a', MAX_PASSWORD_LENGTH);
        $hash = hash_password_argon2id($password);

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue(verify_password($hash, 'legacy-salt', $password));
        self::assertFalse(verify_password($hash, 'legacy-salt', substr($password, 0, -1) . 'b'));
        self::assertFalse(verify_password($hash, 'legacy-salt', $password . 'x'));
    }

    public function testWriterUsesTheSelectedRolloutFormat(): void
    {
        $salt = str_repeat('c', 100);
        $hash = hash_password($salt, 'legacy-compatible');

        if (MODERN_PASSWORD_WRITES) {
            self::assertStringStartsWith('$argon2id$', $hash);
        } else {
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $hash);
        }
        self::assertTrue(verify_password($hash, $salt, 'legacy-compatible'));
    }

    public function testPasswordsLongerThanTheLimitAreRejectedWithoutTruncation(): void
    {
        $password = str_repeat('a', MAX_PASSWORD_LENGTH + 1);

        $this->expectException(InvalidArgumentException::class);
        hash_password_argon2id($password);
    }

    public function testMalformedStoredValuesNeverFallBackToLegacyVerification(): void
    {
        self::assertFalse(verify_password(str_repeat('a', 64), 'salt', 'password'));
        self::assertFalse(verify_password('$2y$10$not-an-argon2id-hash', 'salt', 'password'));
        self::assertFalse(verify_password('not-a-password-hash', 'salt', 'password'));
    }

    public function testHistoricalSha256HashesRemainVerificationOnly(): void
    {
        $salt = str_repeat('b', 100);
        $password = 'legacy-password';
        $hash = hash('sha256', substr($salt, 0, 50) . $password . substr($salt, 50));

        for ($i = 0; $i < 100000; $i++) {
            $hash = hash('sha256', $hash);
        }

        self::assertTrue(verify_password($hash, $salt, $password));
        self::assertFalse(verify_password($hash, $salt, 'wrong-password'));
    }
}
