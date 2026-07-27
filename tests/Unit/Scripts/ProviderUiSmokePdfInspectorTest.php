<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateAssertionException;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ProviderUiSmokePdfInspector.php';

use function ReleaseGate\assertProviderUiSmokePdfText;
use function ReleaseGate\assertProviderUiSmokePdfTextAlternatives;
use function ReleaseGate\countProviderUiSmokeAppointmentRows;
use function ReleaseGate\countProviderUiSmokePdfFragment;
use function ReleaseGate\parseProviderUiSmokePdfInfo;

class ProviderUiSmokePdfInspectorTest extends TestCase
{
    public function testParsesLandscapePdfInfoWithoutReturningRawMetadata(): void
    {
        $parsed = parseProviderUiSmokePdfInfo(
            "Title: synthetic\nPages:          2\nPage size:      841.89 x 595.28 pts (A4)\n",
        );

        self::assertSame(['pages' => 2, 'landscape' => true], $parsed);
    }

    public function testParsesPortraitPdfInfo(): void
    {
        $parsed = parseProviderUiSmokePdfInfo("Pages:          1\nPage size:      595.28 x 841.89 pts (A4)\n");

        self::assertSame(['pages' => 1, 'landscape' => false], $parsed);
    }

    public function testChecksRequiredAndForbiddenTextWithoutReturningText(): void
    {
        assertProviderUiSmokePdfText(
            "Required\nsynthetic   fixture",
            ['required synthetic fixture'],
            ['forbidden'],
            'Synthetic PDF',
        );

        self::addToAssertionCount(1);
    }

    public function testRejectsForbiddenText(): void
    {
        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('exposes forbidden synthetic fixture content');

        assertProviderUiSmokePdfText(
            'safe visible data plus private sentinel',
            [],
            ['private sentinel'],
            'Synthetic PDF',
        );
    }

    public function testAcceptsOneLocalizedAlternativePerGroup(): void
    {
        assertProviderUiSmokePdfTextAlternatives(
            'Date 12/02/2099 from 10:00 to 10:30',
            [['12/02/2099', '02/12/2099', '2099/02/12'], ['10:00', '10:00 am'], ['10:30', '10:30 am']],
            'Synthetic PDF',
        );

        self::addToAssertionCount(1);
    }

    public function testCountsOnlyLinesWithTwoAppointmentTimesAsRows(): void
    {
        $text = "Header\nStand: 27/07/2026 12:45\nSynthetic Parent 12/02/2099 10:00 10:30\n";

        self::assertSame(1, countProviderUiSmokeAppointmentRows($text));
    }

    public function testCountsNormalizedSyntheticFragmentOccurrences(): void
    {
        self::assertSame(
            2,
            countProviderUiSmokePdfFragment("Synthetic   Parent\nsynthetic parent", 'Synthetic Parent'),
        );
    }
}
