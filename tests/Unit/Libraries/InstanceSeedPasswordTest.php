<?php

use PHPUnit\Framework\TestCase;

final class InstanceSeedPasswordTest extends TestCase
{
    public function testNormalAdministratorPasswordsAreRandomStrongAndDistinct(): void
    {
        $instance = (new ReflectionClass(Instance::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Instance::class, 'generateAdministratorPassword');
        $method->setAccessible(true);

        $first = $method->invoke($instance);
        $second = $method->invoke($instance);

        self::assertMatchesRegularExpression('/\A[0-9a-f]{48}\z/', $first);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{48}\z/', $second);
        self::assertNotSame($first, $second);
    }

    public function testDeterministicFixturePasswordIsExplicitlySelectedByConsoleArgument(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/application/controllers/Console.php');

        self::assertStringContainsString("\$fixture === 'fixture' ? 'administrator' : null", $source);
        self::assertStringContainsString('$password = $this->instance->seed();', $source);
        self::assertStringContainsString('Seed completed, login with', $source);
        self::assertStringContainsString('if (!is_cli())', $source);
        self::assertStringContainsString("exit('No direct script access allowed')", $source);
    }

    public function testInstallArgumentsRejectInvalidValuesAndValidateBeforeFreshMigration(): void
    {
        $reflection = new ReflectionClass(Console::class);
        $console = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('installFixtureArgument');
        $method->setAccessible(true);

        self::assertNull($method->invoke($console, ['index.php', 'console', 'install']));
        self::assertSame('fixture', $method->invoke($console, ['index.php', 'console', 'install', 'fixture']));

        $this->expectException(InvalidArgumentException::class);
        $method->invoke($console, ['index.php', 'console', 'install', 'fixture', 'extra']);
    }

    public function testInstallValidatesArgumentsBeforeFreshMigration(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/application/controllers/Console.php');
        $validation = strpos($source, '$fixture = $this->installFixtureArgument');
        $migration = strpos($source, '$this->instance->migrate(\'fresh\')');

        self::assertNotFalse($validation);
        self::assertNotFalse($migration);
        self::assertLessThan($migration, $validation);
    }
}
