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

    public function testNormalizeJsonAssocArrayParsesValidJsonObjectsOnly(): void
    {
        $this->assertSame(['id' => 5], $this->normalizer->normalizeJsonAssocArray('{"id":5}'));
        $this->assertSame([], $this->normalizer->normalizeJsonAssocArray('[1,2,3]'));
        $this->assertSame([], $this->normalizer->normalizeJsonAssocArray('{invalid-json}'));
    }

    public function testNormalizeDateTimeYmdHisValidatesStrictFormat(): void
    {
        $this->assertSame(
            '2026-03-10 11:22:33',
            $this->normalizer->normalizeDateTimeYmdHis('2026-03-10 11:22:33', null),
        );
        $this->assertSame('fallback', $this->normalizer->normalizeDateTimeYmdHis('2026-03-10', 'fallback'));
        $this->assertNull($this->normalizer->normalizeDateTimeYmdHis(null, null));
    }

    public function testNormalizeEnumStringAcceptsOnlyAllowedValues(): void
    {
        $this->assertSame('Booked', $this->normalizer->normalizeEnumString('Booked', ['Booked', 'Cancelled'], null));
        $this->assertSame(
            'Booked',
            $this->normalizer->normalizeEnumString('Unknown', ['Booked', 'Cancelled'], 'Booked'),
        );
    }

    public function testNormalizeFloatHandlesNumericScalarsAndDefaults(): void
    {
        $this->assertSame(1.5, $this->normalizer->normalizeFloat('1.5', null));
        $this->assertSame(2.0, $this->normalizer->normalizeFloat(2, null));
        $this->assertSame(0.25, $this->normalizer->normalizeFloat('invalid', 0.25));
    }
}
