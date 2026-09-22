<?php

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class AppointmentsOpenApiWriteContractTest extends TestCase
{
    public function testAppointmentWriteSchemaDocumentsTheClosedNonEmptyPayload(): void
    {
        $spec = Yaml::parseFile(dirname(__DIR__, 3) . '/openapi.yml');
        self::assertIsArray($spec);

        $schema = $spec['components']['schemas']['AppointmentPayload'] ?? null;
        self::assertIsArray($schema);
        self::assertSame('object', $schema['type'] ?? null);
        self::assertSame(1, $schema['minProperties'] ?? null);
        self::assertFalse($schema['additionalProperties'] ?? true);
        self::assertSame(
            ['start', 'end', 'location', 'color', 'status', 'notes', 'customerId', 'providerId', 'serviceId'],
            array_keys($schema['properties'] ?? []),
        );

        foreach ([['post', '/appointments'], ['put', '/appointments/{appointmentId}']] as [$method, $path]) {
            $responses = $spec['paths'][$path][$method]['responses'] ?? null;
            self::assertIsArray($responses);
            self::assertArrayHasKey('400', $responses);
            self::assertArrayHasKey('415', $responses);
        }
    }
}
