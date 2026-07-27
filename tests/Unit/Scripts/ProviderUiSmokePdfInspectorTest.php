<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateAssertionException;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ProviderUiSmokePdfInspector.php';

use function ReleaseGate\assertProviderUiSmokeDeployedPreparationView;
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

    public function testRejectsReviewedCheckoutTemplateWhenActiveDeploymentHashDiffers(): void
    {
        $viewPath = $this->writePreparationView($this->validPreparationView());

        try {
            assertProviderUiSmokeDeployedPreparationView(
                $viewPath,
                hash('sha256', '<div>active deployment without the reviewed note-line template</div>'),
            );
            self::fail('A local-only template check must not pass for a different active deployment.');
        } catch (GateAssertionException $exception) {
            self::assertStringContainsString('does not match the active deployment', $exception->getMessage());
        } finally {
            unlink($viewPath);
        }
    }

    public function testAcceptsFourLineTemplateOnlyWhenActiveDeploymentHashMatches(): void
    {
        $view = $this->validPreparationView();
        $viewPath = $this->writePreparationView($view);

        try {
            self::assertSame(
                ['active_deployment_matched' => true, 'note_line_count' => 4],
                assertProviderUiSmokeDeployedPreparationView($viewPath, hash('sha256', $view)),
            );
        } finally {
            unlink($viewPath);
        }
    }

    public function testRejectsMatchingActiveTemplateWithoutFourEmptyNoteLines(): void
    {
        $view = <<<'HTML'
        <style>.notes__lines{display:grid;grid-template-rows:repeat(3,1fr)}</style>
        <div class="notes__lines">
          <div class="notes__line"></div>
          <div class="notes__line"></div>
          <div class="notes__line"></div>
        </div>
        HTML;
        $viewPath = $this->writePreparationView($view);

        try {
            assertProviderUiSmokeDeployedPreparationView($viewPath, hash('sha256', $view));
            self::fail('The active deployment must contain exactly four empty note lines.');
        } catch (GateAssertionException $exception) {
            self::assertStringContainsString('four-line source regression is missing', $exception->getMessage());
        } finally {
            unlink($viewPath);
        }
    }

    public function testRejectsUnsafeLocalSymlinkForReviewedCheckoutView(): void
    {
        $view = $this->validPreparationView();
        $targetPath = $this->writePreparationView($view);
        $symlinkPath = $targetPath . '.symlink';

        try {
            self::assertTrue(symlink($targetPath, $symlinkPath));
            self::assertTrue(is_file($symlinkPath));
            self::assertTrue(is_readable($symlinkPath));
            self::assertTrue(is_link($symlinkPath));

            assertProviderUiSmokeDeployedPreparationView($symlinkPath, hash('sha256', $view));
            self::fail('A symlinked reviewed checkout view must not be trusted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('unavailable or unsafe', $exception->getMessage());
        } finally {
            if (is_link($symlinkPath)) {
                unlink($symlinkPath);
            }
            unlink($targetPath);
        }
    }

    private function validPreparationView(): string
    {
        return <<<'HTML'
        <style>.notes__lines{display:grid;grid-template-rows:repeat(4,1fr)}</style>
        <div class="notes__lines">
          <div class="notes__line"></div>
          <div class="notes__line"></div>
          <div class="notes__line"></div>
          <div class="notes__line"></div>
        </div>
        HTML;
    }

    private function writePreparationView(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'provider-ui-smoke-view-');
        self::assertIsString($path);
        self::assertNotFalse(file_put_contents($path, $contents));

        return $path;
    }
}
