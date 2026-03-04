<?php

declare(strict_types=1);

namespace CiContract;

use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ContractAssertionException extends RuntimeException
{
}

final class OpenApiContractValidator
{
    /**
     * @param array<string, mixed> $spec
     */
    private function __construct(private readonly array $spec)
    {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new ContractAssertionException('OpenAPI spec file not found: ' . $path);
        }

        try {
            $parsed = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new ContractAssertionException(
                'Failed to parse OpenAPI spec "' . $path . '": ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!is_array($parsed)) {
            throw new ContractAssertionException('OpenAPI spec root must be a YAML object.');
        }

        return new self($parsed);
    }

    /**
     * @param array<string, mixed> $spec
     */
    public static function fromArray(array $spec): self
    {
        return new self($spec);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOperation(string $method, string $path): array
    {
        $paths = $this->spec['paths'] ?? null;
        if (!is_array($paths)) {
            throw new ContractAssertionException('OpenAPI spec misses "paths" object.');
        }

        $pathItem = $paths[$path] ?? null;
        if (!is_array($pathItem)) {
            throw new ContractAssertionException(sprintf('OpenAPI path "%s" is missing.', $path));
        }

        $normalizedMethod = strtolower($method);
        $operation = $pathItem[$normalizedMethod] ?? null;
        if (!is_array($operation)) {
            throw new ContractAssertionException(
                sprintf('OpenAPI operation "%s %s" is missing.', strtoupper($method), $path),
            );
        }

        return $operation;
    }

    public function assertOperationHasResponse(string $method, string $path, int $statusCode): void
    {
        $operation = $this->getOperation($method, $path);
        $responses = $operation['responses'] ?? null;
        if (!is_array($responses)) {
            throw new ContractAssertionException(
                sprintf('OpenAPI operation "%s %s" has no "responses" section.', strtoupper($method), $path),
            );
        }

        $codeKey = (string) $statusCode;
        if (!array_key_exists($codeKey, $responses)) {
            throw new ContractAssertionException(
                sprintf(
                    'OpenAPI operation "%s %s" does not declare response code %s.',
                    strtoupper($method),
                    $path,
                    $codeKey,
                ),
            );
        }
    }

    public function assertOperationSupportsKnownAuth(string $method, string $path): void
    {
        $operation = $this->getOperation($method, $path);
        $security = $operation['security'] ?? null;
        if (!is_array($security) || $security === []) {
            throw new ContractAssertionException(
                sprintf(
                    'OpenAPI operation "%s %s" misses operation-level security declaration.',
                    strtoupper($method),
                    $path,
                ),
            );
        }

        $supported = false;
        foreach ($security as $securityRequirement) {
            if (!is_array($securityRequirement)) {
                continue;
            }

            if (
                array_key_exists('BasicAuth', $securityRequirement) ||
                array_key_exists('BearerToken', $securityRequirement)
            ) {
                $supported = true;
                break;
            }
        }

        if (!$supported) {
            throw new ContractAssertionException(
                sprintf(
                    'OpenAPI operation "%s %s" security declaration does not include BasicAuth or BearerToken.',
                    strtoupper($method),
                    $path,
                ),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequestSchema(string $method, string $path): array
    {
        $operation = $this->getOperation($method, $path);
        $requestBody = $operation['requestBody'] ?? null;
        if (!is_array($requestBody)) {
            throw new ContractAssertionException(
                sprintf('OpenAPI operation "%s %s" has no "requestBody" section.', strtoupper($method), $path),
            );
        }

        $content = $requestBody['content'] ?? null;
        if (!is_array($content)) {
            throw new ContractAssertionException(
                sprintf('OpenAPI operation "%s %s" requestBody has no "content" section.', strtoupper($method), $path),
            );
        }

        $jsonContent = $content['application/json'] ?? null;
        if (!is_array($jsonContent)) {
            throw new ContractAssertionException(
                sprintf(
                    'OpenAPI operation "%s %s" requestBody does not define "application/json".',
                    strtoupper($method),
                    $path,
                ),
            );
        }

        $schema = $jsonContent['schema'] ?? null;
        if (!is_array($schema)) {
            throw new ContractAssertionException(
                sprintf('OpenAPI operation "%s %s" requestBody has no schema object.', strtoupper($method), $path),
            );
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResponseSchemaOrNull(string $method, string $path, int $statusCode): ?array
    {
        $operation = $this->getOperation($method, $path);
        $responses = $operation['responses'] ?? null;
        if (!is_array($responses)) {
            throw new ContractAssertionException(
                sprintf('OpenAPI operation "%s %s" has no "responses" section.', strtoupper($method), $path),
            );
        }

        $response = $responses[(string) $statusCode] ?? null;
        if (!is_array($response)) {
            throw new ContractAssertionException(
                sprintf(
                    'OpenAPI operation "%s %s" does not declare response code %s.',
                    strtoupper($method),
                    $path,
                    $statusCode,
                ),
            );
        }

        $content = $response['content'] ?? null;
        if ($content === null) {
            return null;
        }

        if (!is_array($content)) {
            throw new ContractAssertionException(
                sprintf(
                    'OpenAPI operation "%s %s" response %d has invalid "content" section.',
                    strtoupper($method),
                    $path,
                    $statusCode,
                ),
            );
        }

        $jsonContent = $content['application/json'] ?? null;
        if ($jsonContent === null) {
            return null;
        }

        if (!is_array($jsonContent)) {
            throw new ContractAssertionException(
                sprintf(
                    'OpenAPI operation "%s %s" response %d has invalid "application/json" definition.',
                    strtoupper($method),
                    $path,
                    $statusCode,
                ),
            );
        }

        $schema = $jsonContent['schema'] ?? null;
        if ($schema === null) {
            return null;
        }

        if (!is_array($schema)) {
            throw new ContractAssertionException(
                sprintf(
                    'OpenAPI operation "%s %s" response %d has no schema object.',
                    strtoupper($method),
                    $path,
                    $statusCode,
                ),
            );
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    public function getResponseSchema(string $method, string $path, int $statusCode): array
    {
        $schema = $this->getResponseSchemaOrNull($method, $path, $statusCode);

        if (is_array($schema)) {
            return $schema;
        }

        throw new ContractAssertionException(
            sprintf(
                'OpenAPI operation "%s %s" response %d has no JSON content schema.',
                strtoupper($method),
                $path,
                $statusCode,
            ),
        );
    }

    /**
     * @param mixed $value
     * @param array<string, mixed> $schema
     */
    public function assertRawJsonValueMatchesSchemaType(mixed $value, array $schema, string $context): void
    {
        $resolvedSchema = $this->resolveSchema($schema);
        $expectedType = $this->resolveSchemaType($resolvedSchema);

        if ($expectedType === null) {
            throw new ContractAssertionException($context . ' has schema without resolvable type.');
        }

        $actualType = $this->detectRawDecodedJsonType($value);

        if (!$this->isRawTypeCompatible($actualType, $expectedType)) {
            throw new ContractAssertionException(
                sprintf('%s expected JSON type "%s", got "%s".', $context, $expectedType, $actualType),
            );
        }
    }

    /**
     * @param mixed $value
     * @param array<string, mixed> $schema
     */
    public function assertValueMatchesSchema(
        mixed $value,
        array $schema,
        string $context,
        bool $allowEmptyListAsObject = false,
        mixed $rawValue = null,
    ): void {
        $resolvedSchema = $this->resolveSchema($schema);
        $type = $this->resolveSchemaType($resolvedSchema);

        if ($type === null) {
            throw new ContractAssertionException($context . ' has schema without resolvable type.');
        }

        if (!$this->valueMatchesType($value, $type, $allowEmptyListAsObject, $rawValue)) {
            throw new ContractAssertionException(
                sprintf('%s expected type "%s", got %s.', $context, $type, $this->describePhpType($value)),
            );
        }

        if ($type === 'array') {
            $itemsSchema = $resolvedSchema['items'] ?? null;
            if (!is_array($itemsSchema)) {
                return;
            }

            foreach ($value as $index => $item) {
                $rawItem = null;
                if (is_array($rawValue) && array_key_exists($index, $rawValue)) {
                    $rawItem = $rawValue[$index];
                }

                $this->assertValueMatchesSchema($item, $itemsSchema, $context . '[' . $index . ']', false, $rawItem);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param string[] $fields
     */
    public function assertObjectFieldsMatchSchema(
        array $payload,
        string $schemaRef,
        array $fields,
        string $context,
        mixed $rawPayload = null,
    ): void {
        $schema = $this->resolveSchemaByRef($schemaRef);
        $properties = $schema['properties'] ?? null;
        if (!is_array($properties)) {
            throw new ContractAssertionException(sprintf('Schema "%s" has no "properties" section.', $schemaRef));
        }

        foreach ($fields as $field) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            if (!array_key_exists($field, $payload)) {
                throw new ContractAssertionException($context . ' misses required field "' . $field . '".');
            }

            $fieldSchema = $properties[$field] ?? null;
            if (!is_array($fieldSchema)) {
                throw new ContractAssertionException(
                    sprintf('Schema "%s" has no property schema for "%s".', $schemaRef, $field),
                );
            }

            $rawField = null;
            if (is_object($rawPayload) && property_exists($rawPayload, $field)) {
                $rawField = $rawPayload->{$field};
            } elseif (is_array($rawPayload) && array_key_exists($field, $rawPayload)) {
                $rawField = $rawPayload[$field];
            }

            $this->assertValueMatchesSchema($payload[$field], $fieldSchema, $context . '.' . $field, false, $rawField);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function resolveSchema(array $schema): array
    {
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            return $this->resolveSchemaByRef($schema['$ref']);
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSchemaByRef(string $ref): array
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            throw new ContractAssertionException('Unsupported schema reference: ' . $ref);
        }

        $schemaName = substr($ref, strlen($prefix));
        if ($schemaName === '') {
            throw new ContractAssertionException('Invalid schema reference: ' . $ref);
        }

        $schemas = $this->spec['components']['schemas'] ?? null;
        if (!is_array($schemas)) {
            throw new ContractAssertionException('OpenAPI spec misses "components.schemas".');
        }

        $schema = $schemas[$schemaName] ?? null;
        if (!is_array($schema)) {
            throw new ContractAssertionException('Schema not found: ' . $ref);
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function resolveSchemaType(array $schema): ?string
    {
        $type = $schema['type'] ?? null;
        if (is_string($type) && $type !== '') {
            return $type;
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            return 'object';
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            return 'array';
        }

        return null;
    }

    private function valueMatchesType(
        mixed $value,
        string $type,
        bool $allowEmptyListAsObject,
        mixed $rawValue = null,
    ): bool {
        if ($rawValue !== null && in_array($type, ['object', 'array'], true)) {
            $rawType = $this->detectRawDecodedJsonType($rawValue);

            if ($type === 'object') {
                if ($rawType === 'object') {
                    return true;
                }

                if ($allowEmptyListAsObject && $rawType === 'object' && is_array($value) && $value === []) {
                    return true;
                }

                return false;
            }

            if ($type === 'array') {
                return $rawType === 'array';
            }
        }

        return match ($type) {
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'object' => is_array($value) && (!array_is_list($value) || ($allowEmptyListAsObject && $value === [])),
            'array' => is_array($value) && array_is_list($value),
            default => false,
        };
    }

    private function detectRawDecodedJsonType(mixed $value): string
    {
        return match (true) {
            is_object($value) => 'object',
            is_array($value) => 'array',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_bool($value) => 'boolean',
            $value === null => 'null',
            default => gettype($value),
        };
    }

    private function isRawTypeCompatible(string $actualType, string $expectedType): bool
    {
        if ($expectedType === 'number') {
            return in_array($actualType, ['number', 'integer'], true);
        }

        return $actualType === $expectedType;
    }

    private function describePhpType(mixed $value): string
    {
        if (is_array($value)) {
            return array_is_list($value) ? 'array(list)' : 'array(object)';
        }

        return gettype($value);
    }
}
