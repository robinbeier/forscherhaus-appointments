<?php

namespace Tests\Unit\Libraries;

use Api;
use Tests\TestCase;

require_once APPPATH . 'libraries/Api.php';

final class ApiRequestWithTest extends TestCase
{
    private array $originalGet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalGet = $_GET;
    }

    protected function tearDown(): void
    {
        $_GET = $this->originalGet;

        parent::tearDown();
    }

    public function testRequestWithDeduplicatesRelationsInRequestOrder(): void
    {
        $_GET['with'] = 'services,providers,services,customers,providers';

        $with = (new Api())->request_with();

        self::assertSame(['services', 'providers', 'customers'], $with);
    }

    public function testRequestWithRejectsMoreThanSixteenUniqueRelationsBeforeExpansion(): void
    {
        $_GET['with'] = implode(',', array_map(static fn(int $index): string => 'relation_' . $index, range(1, 17)));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 16 unique relations');

        (new Api())->request_with();
    }

    public function testRequestWithRejectsMoreThanSixtyFourRawTokensBeforeDeduplication(): void
    {
        $_GET['with'] = implode(',', array_fill(0, 65, 'services'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 64 relation tokens');

        (new Api())->request_with();
    }

    public function testRequestWithRejectsOverlongRelationName(): void
    {
        $_GET['with'] = str_repeat('r', 65);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('relation name that is too long');

        (new Api())->request_with();
    }
}
