<?php

namespace Tests\Unit\Views;

use Tests\TestCase;

class BookingConfirmationJsonTest extends TestCase
{
    public function testEmbeddedConfirmationJsonCannotBreakOutOfScriptAndRoundTrips(): void
    {
        $source = file_get_contents(APPPATH . 'views/pages/booking_confirmation.php');
        $this->assertIsString($source);

        $cases = [
            'share_payload' => [
                'payload' => [
                    'title' => 'Termin für Jörg & Ana',
                    'text' => 'Link: https://example.test/booking?a=1&b=2 📅',
                    'closing_tag' => '</ScRiPt><script>alert("x")</script>',
                ],
                'pattern' => '/json_encode\(\s*\$share_payload,\s*(?<flags>[^)]*?)\)/s',
            ],
            'appointment_pdf_data' => [
                'payload' => [
                    'customer' => 'Müller & O\'Connor',
                    'notes' => 'Quote: "ready"; URL https://example.test/a/b',
                    'closing_tag' => '</sCrIpT><img src=x onerror=alert(1)>',
                ],
                'pattern' => '/json_encode\(\s*\$appointment_pdf_data,\s*(?<flags>[^)]*?)\)/s',
            ],
        ];

        foreach ($cases as $name => $case) {
            preg_match($case['pattern'], $source, $matches);
            $this->assertArrayHasKey('flags', $matches, $name);

            $expression = 'json_encode($value, ' . trim($matches['flags']) . ')';
            $value = $case['payload'];
            $encoded = eval('return ' . $expression . ';');

            $this->assertIsString($encoded, $name);
            $this->assertStringNotContainsString('<', $encoded, $name);
            $this->assertStringNotContainsString('>', $encoded, $name);
            $this->assertSame($case['payload'], json_decode($encoded, true, 512, JSON_THROW_ON_ERROR), $name);
        }
    }
}
