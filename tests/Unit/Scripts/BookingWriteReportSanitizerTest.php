<?php

namespace Tests\Unit\Scripts;

use CiContract\BookingWriteReportSanitizer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/lib/BookingWriteReportSanitizer.php';

final class BookingWriteReportSanitizerTest extends TestCase
{
    public function testSanitizedReportJsonRedactsCapabilitiesAuthorityAndPersonalData(): void
    {
        $appointmentHash = bin2hex(random_bytes(16));
        $authority = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $email = bin2hex(random_bytes(8)) . '@example.invalid';

        $sanitized = BookingWriteReportSanitizer::sanitize([
            'primary_appointment_id' => 77123,
            'primary_appointment_hash' => $appointmentHash,
            'public_reschedule_authority' => $authority,
            'public_reschedule_authority_context' => $authority,
            'provider_id' => 88234,
            'customer' => [
                'email' => $email,
                'first_name' => bin2hex(random_bytes(4)),
            ],
            'cleanup' => [
                'created' => [['resource' => 'appointment', 'id' => 99345]],
            ],
            'reschedule_url' => 'booking/reschedule/' . $appointmentHash,
            'cancellation_url' => 'booking_cancellation/of/' . $appointmentHash,
        ]);

        $json = json_encode($sanitized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->assertSame('[redacted]', $sanitized['primary_appointment_id']);
        $this->assertSame('[redacted]', $sanitized['primary_appointment_hash']);
        $this->assertSame('[redacted]', $sanitized['public_reschedule_authority']);
        $this->assertSame('[redacted]', $sanitized['public_reschedule_authority_context']);
        $this->assertSame('[redacted]', $sanitized['provider_id']);
        $this->assertSame('[redacted]', $sanitized['customer']['email']);
        $this->assertSame('[redacted]', $sanitized['cleanup']['created'][0]['id']);
        $this->assertSame('booking/reschedule/[redacted]', $sanitized['reschedule_url']);
        $this->assertSame('booking_cancellation/of/[redacted]', $sanitized['cancellation_url']);
        $this->assertStringNotContainsString($appointmentHash, $json);
        $this->assertStringNotContainsString($authority, $json);
        $this->assertStringNotContainsString($email, $json);
    }
}
