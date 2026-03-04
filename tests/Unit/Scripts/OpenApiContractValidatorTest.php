<?php

namespace Tests\Unit\Scripts;

use CiContract\ContractAssertionException;
use CiContract\OpenApiContractValidator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/lib/OpenApiContractValidator.php';

class OpenApiContractValidatorTest extends TestCase
{
    public function testGetOperationReturnsConfiguredOperation(): void
    {
        $validator = OpenApiContractValidator::fromArray($this->specFixture());
        $operation = $validator->getOperation('GET', '/appointments');

        $this->assertArrayHasKey('responses', $operation);
        $this->assertArrayHasKey('security', $operation);
    }

    public function testGetOperationFailsForMissingPathOrMethod(): void
    {
        $validator = OpenApiContractValidator::fromArray($this->specFixture());

        $this->expectException(ContractAssertionException::class);
        $this->expectExceptionMessage('OpenAPI path "/missing" is missing.');

        $validator->getOperation('GET', '/missing');
    }

    public function testAssertOperationHasResponseFailsForMissingStatusCode(): void
    {
        $validator = OpenApiContractValidator::fromArray($this->specFixture());

        $this->expectException(ContractAssertionException::class);
        $this->expectExceptionMessage('does not declare response code 500');

        $validator->assertOperationHasResponse('GET', '/appointments', 500);
    }

    public function testGetRequestSchemaReturnsConfiguredSchema(): void
    {
        $validator = OpenApiContractValidator::fromArray($this->specFixture());
        $schema = $validator->getRequestSchema('POST', '/appointments');

        $this->assertArrayHasKey('$ref', $schema);
        $this->assertSame('#/components/schemas/AppointmentPayload', $schema['$ref']);
    }

    public function testGetResponseSchemaOrNullReturnsNullForNoContentResponses(): void
    {
        $validator = OpenApiContractValidator::fromArray($this->specFixture());
        $schema = $validator->getResponseSchemaOrNull('DELETE', '/appointments/{appointmentId}', 204);

        $this->assertNull($schema);
    }

    public function testAssertValueMatchesSchemaFailsWhenTopLevelTypeMismatches(): void
    {
        $validator = OpenApiContractValidator::fromArray($this->specFixture());
        $schema = $validator->getResponseSchema('GET', '/appointments', 200);

        $this->expectException(ContractAssertionException::class);
        $this->expectExceptionMessage('expected type "array"');

        $validator->assertValueMatchesSchema(['id' => 1], $schema, 'appointments_index');
    }

    public function testAssertObjectFieldsMatchSchemaFailsWhenFieldTypeIsWrong(): void
    {
        $validator = OpenApiContractValidator::fromArray($this->specFixture());

        $this->expectException(ContractAssertionException::class);
        $this->expectExceptionMessage('expected type "integer"');

        $validator->assertObjectFieldsMatchSchema(
            [
                'id' => 'not-int',
                'status' => 'Booked',
            ],
            '#/components/schemas/AppointmentRecord',
            ['id', 'status'],
            'appointment',
        );
    }

    public function testAssertValueMatchesSchemaRejectsEmptyListForObjectByDefault(): void
    {
        $validator = OpenApiContractValidator::fromArray($this->specFixture());

        $this->expectException(ContractAssertionException::class);
        $this->expectExceptionMessage('expected type "object"');

        $validator->assertValueMatchesSchema([], ['type' => 'object'], 'empty_object');
    }

    public function testAssertValueMatchesSchemaAcceptsEmptyJsonObjectWhenExplicitlyAllowed(): void
    {
        $validator = OpenApiContractValidator::fromArray($this->specFixture());

        $validator->assertValueMatchesSchema([], ['type' => 'object'], 'empty_object', true);
        $this->addToAssertionCount(1);
    }

    public function testAssertRawJsonValueMatchesSchemaTypeRejectsObjectForArraySchema(): void
    {
        $validator = OpenApiContractValidator::fromArray($this->specFixture());
        $schema = $validator->getResponseSchema('GET', '/appointments', 200);

        $this->expectException(ContractAssertionException::class);
        $this->expectExceptionMessage('expected JSON type "array", got "object"');

        $validator->assertRawJsonValueMatchesSchemaType((object) [], $schema, 'appointments_index');
    }

    public function testAssertRawJsonValueMatchesSchemaTypeAcceptsEmptyObject(): void
    {
        $validator = OpenApiContractValidator::fromArray($this->specFixture());

        $validator->assertRawJsonValueMatchesSchemaType((object) [], ['type' => 'object'], 'empty_object');
        $this->addToAssertionCount(1);
    }

    public function testAssertValueMatchesSchemaAcceptsNumericKeyObjectWhenRawValueIsObject(): void
    {
        $validator = OpenApiContractValidator::fromArray($this->specFixture());

        $validator->assertValueMatchesSchema(
            [0 => 'a'],
            ['type' => 'object'],
            'numeric_key_object',
            false,
            (object) ['0' => 'a'],
        );
        $this->addToAssertionCount(1);
    }

    public function testAssertValueMatchesSchemaAcceptsNestedNumericKeyObjectWhenRawItemsAreObjects(): void
    {
        $validator = OpenApiContractValidator::fromArray($this->specFixture());

        $validator->assertValueMatchesSchema(
            [[0 => 'a']],
            [
                'type' => 'array',
                'items' => ['type' => 'object'],
            ],
            'nested_numeric_key_object',
            false,
            [(object) ['0' => 'a']],
        );
        $this->addToAssertionCount(1);
    }

    public function testAssertObjectFieldsMatchSchemaAcceptsNumericKeyObjectWhenRawPayloadFieldIsObject(): void
    {
        $validator = OpenApiContractValidator::fromArray($this->specFixture());

        $validator->assertObjectFieldsMatchSchema(
            [
                'metadata' => [0 => 'a'],
            ],
            '#/components/schemas/AppointmentRecord',
            ['metadata'],
            'appointment',
            (object) [
                'metadata' => (object) ['0' => 'a'],
            ],
        );
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function specFixture(): array
    {
        return [
            'openapi' => '3.0.3',
            'paths' => [
                '/appointments' => [
                    'get' => [
                        'security' => [['BasicAuth' => []]],
                        'responses' => [
                            '200' => [
                                'description' => 'OK',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'array',
                                            'items' => [
                                                '$ref' => '#/components/schemas/AppointmentRecord',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '401' => [
                                'description' => 'Unauthorized',
                            ],
                        ],
                    ],
                    'post' => [
                        'security' => [['BasicAuth' => []]],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/AppointmentPayload',
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '201' => [
                                'description' => 'Created',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            '$ref' => '#/components/schemas/AppointmentRecord',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '/appointments/{appointmentId}' => [
                    'delete' => [
                        'security' => [['BasicAuth' => []]],
                        'responses' => [
                            '204' => [
                                'description' => 'No Content',
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'AppointmentRecord' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'status' => ['type' => 'string'],
                            'metadata' => ['type' => 'object'],
                        ],
                    ],
                    'AppointmentPayload' => [
                        'type' => 'object',
                        'properties' => [
                            'start' => ['type' => 'string'],
                            'end' => ['type' => 'string'],
                            'customerId' => ['type' => 'integer'],
                            'providerId' => ['type' => 'integer'],
                            'serviceId' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
