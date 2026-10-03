<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use EA_Input;
use ReflectionProperty;
use Tests\TestCase;

/** Regression coverage for one JSON decode per request body. */
final class EAInputJsonDecodeCacheTest extends TestCase
{
    private EA_Input $originalInput;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalInput = get_instance()->input;
    }

    protected function tearDown(): void
    {
        get_instance()->input = $this->originalInput;
        parent::tearDown();
    }

    public function testMultipleFieldReadsUseTheSameDecodedRequestPayload(): void
    {
        $input = new class (get_instance()->security) extends EA_Input {
            public function get_request_header($index, $xss_clean = false)
            {
                return strtolower($index) === 'content-type' ? 'application/json' : null;
            }
        };
        $this->setProtected($input, '_raw_input_stream', '{"first":"original","second":"original"}');
        get_instance()->input = $input;

        self::assertSame('original', $input->json('first'));

        // A second read must not re-read or re-decode a mutable input stream.
        $this->setProtected($input, '_raw_input_stream', '{"first":"changed","second":"changed"}');

        self::assertSame('original', $input->json('second'));
    }

    private function setProtected(EA_Input $input, string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty(EA_Input::class, $property);
        $reflection->setValue($input, $value);
    }
}
