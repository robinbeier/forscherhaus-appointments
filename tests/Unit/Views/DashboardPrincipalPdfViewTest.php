<?php

namespace Tests\Unit\Views;

use Tests\TestCase;

class DashboardPrincipalPdfViewTest extends TestCase
{
    public function testOffersRemainActionableWithoutStackedStatusBadges(): void
    {
        $output = $this->render([
            $this->metric([
                'provider_name' => 'Beispiel <script>alert(1)</script>',
                'booked_appointments_raw' => 0,
                'slots_planned_raw' => 17,
                'slots_required_raw' => 20,
                'has_capacity_gap' => true,
                'status_reasons' => ['booking_goal_missed', 'after_15_goal_missed', 'capacity_gap'],
            ]),
        ]);

        self::assertStringContainsString('Terminangebot ergänzen', $output);
        self::assertStringContainsString('3 zusätzliche Termine erforderlich.', $output);
        self::assertStringContainsString('Auch Angebot nach 15 Uhr prüfen.', $output);
        self::assertStringContainsString('Beispiel &lt;script&gt;', $output);
        self::assertStringNotContainsString('<script>alert(1)</script>', $output);
        self::assertStringNotContainsString('status-list', $output);
    }

    public function testNoPlanOrMissingTargetIsNotReportedAsBookingGoalReached(): void
    {
        $output = $this->render([
            $this->metric(['has_plan' => false, 'slots_planned_raw' => null]),
            $this->metric(['has_explicit_target' => false, 'is_target_fallback' => true]),
        ]);

        self::assertStringContainsString('Terminangebot prüfen', $output);
        self::assertStringContainsString('Kein Klassenziel bewertet', $output);
        self::assertStringContainsString('Ohne festgelegte Klassengröße', $output);
        self::assertStringNotContainsString('Buchungsziel erreicht', $output);
    }

    public function testExplicitZeroTargetIsRenderedAsAClassTarget(): void
    {
        $output = $this->render(
            [
                $this->metric([
                    'target_raw' => 0,
                    'booked_raw' => 0,
                    'booked_appointments_raw' => 0,
                    'has_explicit_target' => true,
                    'is_target_fallback' => false,
                ]),
            ],
            ['appointment_count_total' => 0, 'explicit_target_total' => 0, 'explicit_target_complete' => true],
        );

        self::assertStringContainsString('>0</div><div class="label">Benötigte Termine', $output);
        self::assertStringContainsString('Buchungsziel erreicht', $output);
        self::assertStringNotContainsString('Automatische Zielgröße', $output);
        self::assertStringNotContainsString('Kein Klassenziel bewertet', $output);
    }

    public function testTrueAppointmentCountDoesNotBecomeAClaimAboutReachedFamilies(): void
    {
        $output = $this->render(
            [$this->metric(['booked_appointments_raw' => 3, 'booked_raw' => 9])],
            ['appointment_count_total' => 3, 'booked_distinct_total' => 9, 'missing_parents_total' => 17],
        );

        self::assertStringContainsString('>3</div><div class="label">Gebuchte Termine', $output);
        self::assertStringContainsString('Benötigte<br>Termine', $output);
        self::assertStringContainsString('Termine zählen Buchungen.', $output);
        self::assertStringNotContainsString('Eltern erreicht', $output);
        self::assertStringNotContainsString('Fehlende Eltern', $output);
    }

    public function testUnavailableAppointmentCountIsNotInventedFromLegacySlotCount(): void
    {
        $output = $this->render(
            [$this->metric(['booked_appointments_raw' => null, 'booked_raw' => 9])],
            ['appointment_count_total' => null, 'booked_total' => 9],
        );

        self::assertStringContainsString('>—</div><div class="label">Gebuchte Termine', $output);
        self::assertStringNotContainsString('Buchungsziel erreicht', $output);
    }

    public function testEmptySelectionExplainsTheMissingDataWithoutClaimingCompletion(): void
    {
        $output = $this->render([], ['appointment_count_total' => 0]);

        self::assertStringContainsString('Keine Daten für diese Auswahl.', $output);
        self::assertStringContainsString('kein Nachweis, dass alle Buchungen erledigt sind', $output);
        self::assertStringContainsString('Keine Lehrkräfte in der aktuellen Auswahl.', $output);
    }

    public function testFullClassTargetRemainsOpenAfterTheLegacyThreshold(): void
    {
        $output = $this->render(
            [
                $this->metric([
                    'target_raw' => 24,
                    'booked_appointments_raw' => 23,
                    'booked_raw' => 48,
                    'gap_to_threshold' => 0,
                    'status_reasons' => [],
                ]),
            ],
            ['appointment_count_total' => 23, 'explicit_target_total' => 24, 'explicit_target_complete' => true],
        );
        self::assertStringContainsString('1 Buchungen bis zum Ziel.', $output);
        self::assertStringNotContainsString('Buchungsziel erreicht', $output);
        self::assertStringContainsString('>24</div><div class="label">Benötigte Termine', $output);
        self::assertStringNotContainsString('90 %', $output);
    }

    public function testPartialTargetIsLabelledAsIncomplete(): void
    {
        $output = $this->render(
            [$this->metric()],
            [
                'appointment_count_total' => 15,
                'explicit_target_total' => 20,
                'explicit_target_count' => 1,
                'explicit_target_complete' => false,
            ],
        );
        self::assertStringContainsString('Bekannte Ziele; Auswahl ist unvollständig', $output);
    }

    public function testTargetCaptionUsesPresenceRatherThanSumForMixedSelections(): void
    {
        foreach (
            [
                [0, 1, false, 'Bekannte Ziele; Auswahl ist unvollständig'],
                [0, 0, false, 'Kein festgelegtes Ziel'],
                [0, 2, true, 'Summe der festgelegten Klassengrößen'],
                [12, 1, false, 'Bekannte Ziele; Auswahl ist unvollständig'],
            ]
            as [$total, $count, $complete, $caption]
        ) {
            $output = $this->render(
                [
                    $this->metric(['target_raw' => $total, 'has_explicit_target' => $count > 0]),
                    $this->metric(['target_raw' => 0, 'has_explicit_target' => $count > 1]),
                ],
                [
                    'appointment_count_total' => 0,
                    'explicit_target_total' => $total,
                    'explicit_target_count' => $count,
                    'explicit_target_complete' => $complete,
                ],
            );
            self::assertStringContainsString('<div class="caption">' . $caption . '</div>', $output);
        }
    }

    private function metric(array $overrides = []): array
    {
        return array_replace(
            [
                'provider_name' => 'Mira Beispiel',
                'target_raw' => 20,
                'booked_raw' => 15,
                'booked_appointments_raw' => 15,
                'gap_to_threshold' => 3,
                'slots_planned_raw' => 22,
                'slots_required_raw' => 22,
                'has_capacity_gap' => false,
                'has_plan' => true,
                'has_explicit_target' => true,
                'status_reasons' => ['booking_goal_missed'],
            ],
            $overrides,
        );
    }

    private function render(array $metrics, array $summary = ['appointment_count_total' => 0]): string
    {
        $school_name = 'Beispielschule';
        $logo_data_url = null;
        $generated_at_text = '08.09.2026, 23:00';
        $period_label = '18.11.2026';
        $threshold_ratio = 0.9;
        $service_label = 'Elternsprechtag';
        $status_label = 'Gebucht';

        ob_start();
        include APPPATH . 'views/exports/dashboard_principal_pdf.php';
        return (string) ob_get_clean();
    }
}
