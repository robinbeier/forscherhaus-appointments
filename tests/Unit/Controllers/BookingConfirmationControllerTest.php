<?php

namespace Tests\Unit\Controllers;

use Booking_confirmation;
use InvalidArgumentException;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

require_once APPPATH . 'bootstrap/SentryBootstrap.php';
require_once APPPATH . 'controllers/Booking_confirmation.php';

class BookingConfirmationControllerTest extends TestCase
{
    public function testCaptureConfirmationExceptionSendsSentryEventWithAppointmentContext(): void
    {
        $transport = $this->createMemoryTransport();

        \Sentry\init([
            'dsn' => 'https://examplePublicKey@o0.ingest.sentry.io/1',
            'default_integrations' => false,
            'transport' => $transport,
        ]);

        try {
            $_SERVER['REQUEST_URI'] = '/index.php/booking_confirmation/of/hash-123';

            $controller = new class extends Booking_confirmation {
                public function __construct() {}

                public function callCaptureConfirmationException(\Throwable $exception, string $appointmentHash): void
                {
                    $this->captureConfirmationException($exception, $appointmentHash);
                }
            };

            $controller->callCaptureConfirmationException(
                new InvalidArgumentException('missing relation'),
                'hash-123',
            );

            \Sentry\flush();

            $this->assertNotNull($transport->event);
            $this->assertSame('booking_confirmation', $transport->event->getTags()['area'] ?? null);
            $this->assertSame(
                'resolve_related_entities',
                $transport->event->getTags()['operation'] ?? null,
            );
            $this->assertSame('hash-123', $transport->event->getExtra()['appointment_hash'] ?? null);
            $this->assertSame(
                '/index.php/booking_confirmation/of/hash-123',
                $transport->event->getExtra()['request_uri'] ?? null,
            );
        } finally {
            unset($_SERVER['REQUEST_URI']);
            SentrySdk::setCurrentHub(new Hub());
        }
    }

    private function createMemoryTransport(): TransportInterface
    {
        return new class() implements TransportInterface {
            public ?Event $event = null;

            public function send(Event $event): Result
            {
                $this->event = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success(), $this->event);
            }
        };
    }
}
