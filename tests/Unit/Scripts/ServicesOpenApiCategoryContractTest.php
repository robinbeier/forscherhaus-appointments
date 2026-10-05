<?php

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ServicesOpenApiCategoryContractTest extends TestCase
{
    public function testServiceCategoryProjectionDeclaresNullableObjectShape(): void
    {
        $spec = Yaml::parseFile(dirname(__DIR__, 3) . '/openapi.yml');
        $category = $spec['components']['schemas']['ServiceRecord']['properties']['category'] ?? null;

        self::assertIsArray($category);
        self::assertSame('object', $category['type'] ?? null);
        self::assertTrue($category['nullable'] ?? false);
        self::assertArrayNotHasKey('allOf', $category);
        self::assertSame(['type' => 'integer'], $category['properties']['id'] ?? null);
        self::assertSame(['type' => 'string', 'nullable' => true], $category['properties']['name'] ?? null);
        self::assertSame(['type' => 'string', 'nullable' => true], $category['properties']['description'] ?? null);
    }
}
