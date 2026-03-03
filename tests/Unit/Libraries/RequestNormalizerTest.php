<?php

namespace Tests\Unit\Libraries;

use PHPUnit\Framework\TestCase;
use Request_normalizer;

require_once APPPATH . 'libraries/Request_normalizer.php';

class RequestNormalizerTest extends TestCase
{
    private Request_normalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new Request_normalizer();
    }

    public function testNormalizeStringTrimsAndHandlesEmptyOptionalValues(): void
    {
        $this->assertSame('value', $this->normalizer->normalizeString('  value  ', null, true));
        $this->assertNull($this->normalizer->normalizeString('   ', null, true));
        $this->assertSame('', $this->normalizer->normalizeString('   ', '', false));
    }

    public function testNormalizeIntAndPositiveIntUseDefaultsForInvalidValues(): void
    {
        $this->assertSame(42, $this->normalizer->normalizeInt('42', 7));
        $this->assertSame(7, $this->normalizer->normalizeInt('invalid', 7));
        $this->assertSame(9, $this->normalizer->normalizePositiveInt('9', 1));
        $this->assertSame(1, $this->normalizer->normalizePositiveInt('-5', 1));
    }

    public function testNormalizeBoolAcceptsCommonTruthyAndFalsyForms(): void
    {
        $this->assertTrue($this->normalizer->normalizeBool('yes', false));
        $this->assertTrue($this->normalizer->normalizeBool('1', false));
        $this->assertFalse($this->normalizer->normalizeBool('off', true));
        $this->assertFalse($this->normalizer->normalizeBool('0', true));
        $this->assertTrue($this->normalizer->normalizeBool('unknown', true));
    }

    public function testNormalizeDateYmdValidatesStrictFormat(): void
    {
        $this->assertSame('2026-03-15', $this->normalizer->normalizeDateYmd('2026-03-15', null));
        $this->assertSame('fallback', $this->normalizer->normalizeDateYmd('2026-02-30', 'fallback'));
        $this->assertNull($this->normalizer->normalizeDateYmd(null, null));
    }

    public function testNormalizeStringListDropsEmptyEntriesAndKeepsOrder(): void
    {
        $this->assertSame(['a', 'b'], $this->normalizer->normalizeStringList([' a ', '', 'b', '   ']));
        $this->assertSame(['single'], $this->normalizer->normalizeStringList(' single '));
    }

    public function testNormalizePositiveIntListKeepsUniquePositiveValuesOnly(): void
    {
        $this->assertSame([1, 3], $this->normalizer->normalizePositiveIntList(['1', 1, 0, -2, '3', '3']));
    }

    public function testNormalizeAssocArrayReturnsEmptyArrayForLists(): void
    {
        $this->assertSame(['key' => 'value'], $this->normalizer->normalizeAssocArray(['key' => 'value']));
        $this->assertSame([], $this->normalizer->normalizeAssocArray(['value']));
        $this->assertSame([], $this->normalizer->normalizeAssocArray('not-an-array'));
    }
}
