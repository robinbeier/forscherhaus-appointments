<?php
$metrics = array_values($metrics ?? []);
$number = static fn(?int $value): string => $value === null ? '—' : number_format($value, 0, ',', '.');
$rows = [];
$offerAttention = 0;
$bookingAttention = 0;
foreach ($metrics as $metric) {
    $target = max(0, (int) ($metric['target_raw'] ?? 0));
    $planned = isset($metric['slots_planned_raw']) ? max(0, (int) $metric['slots_planned_raw']) : null;
    $required = isset($metric['slots_required_raw']) ? max(0, (int) $metric['slots_required_raw']) : null;
    $booked = isset($metric['booked_appointments_raw']) ? max(0, (int) $metric['booked_appointments_raw']) : null;
    $explicit = !empty($metric['has_explicit_target']);
    $hasPlan = !empty($metric['has_plan']);
    $hasTarget = $explicit || $target > 0;
    $offerIssue = !empty($metric['has_capacity_gap']) || ($hasTarget && (!$hasPlan || $planned === null));
    $missingBookings = $explicit && $target > 0 && $booked !== null ? max($target - $booked, 0) : 0;
    $bookingIssue = $explicit && $target > 0 && $hasPlan && $missingBookings > 0;
    $reasons = is_array($metric['status_reasons'] ?? null) ? $metric['status_reasons'] : [];
    $lateIssue = in_array('after_15_goal_missed', $reasons, true);
    $offerAttention += (int) $offerIssue;
    $bookingAttention += (int) $bookingIssue;

    if ($offerIssue) {
        $action =
            $planned === 0
                ? 'Termine anbieten'
                : ($planned === null || !$hasPlan
                    ? 'Terminangebot prüfen'
                    : 'Terminangebot ergänzen');
        $detail =
            $planned !== null && $required !== null && $required > $planned
                ? $number($required - $planned) . ' zusätzliche Termine erforderlich.'
                : 'Planungsgrundlage unklar.';
    } elseif ($bookingIssue) {
        $action = $booked === 0 ? 'Buchungsstart prüfen' : 'An Buchung erinnern';
        $detail = $number($missingBookings) . ' Buchungen bis zum Ziel.';
    } elseif ($booked === null) {
        $action = 'Buchungszahl prüfen';
        $detail = 'Keine verlässliche Terminzahl verfügbar.';
    } elseif ($lateIssue) {
        $action = 'Spätere Termine prüfen';
        $detail = 'Vorgabe nach 15 Uhr noch offen.';
    } elseif (!$explicit) {
        $action = 'Kein Klassenziel bewertet';
        $detail = 'Automatische oder fehlende Zielgröße.';
    } else {
        $action = 'Buchungsziel erreicht';
        $detail = '';
    }
    if ($lateIssue && ($offerIssue || $bookingIssue)) {
        $detail .= ' Auch Angebot nach 15 Uhr prüfen.';
    }
    $rows[] = compact(
        'metric',
        'target',
        'planned',
        'required',
        'booked',
        'explicit',
        'offerIssue',
        'bookingIssue',
        'lateIssue',
        'action',
        'detail',
    );
}
$appointmentCount = isset($summary['appointment_count_total']) ? (int) $summary['appointment_count_total'] : null;
$requiredCount = array_key_exists('explicit_target_total', $summary) ? (int) $summary['explicit_target_total'] : null;
$requiredComplete = !empty($summary['explicit_target_complete']);
$requiredCaption = $requiredComplete
    ? 'Summe der festgelegten Klassengrößen'
    : ((int) ($summary['explicit_target_count'] ?? 0) === 0
        ? 'Kein festgelegtes Ziel'
        : 'Bekannte Ziele; Auswahl ist unvollständig');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8" />
<title>Buchungsstand – <?= html_escape($school_name ?? 'Forscherhaus') ?></title>
<style>
@page {
    size: A4;
    margin: 14mm 13mm 18mm;
    @bottom-left { content: "Schulleitungsreport · Interner Gebrauch"; font: 8pt Arial, sans-serif; color: #68746e; }
    @bottom-right { content: "Seite " counter(page) " von " counter(pages); font: 8pt Arial, sans-serif; color: #68746e; }
}
* { box-sizing: border-box; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { margin: 0; font: 10pt/1.45 Arial, "Noto Sans", sans-serif; color: #20352b; }
header { border-top: 3px solid #236344; padding-top: 10pt; margin-bottom: 13pt; }
.school { font-size: 10pt; font-weight: bold; overflow-wrap: anywhere; }
.brand { display: flex; align-items: flex-start; justify-content: space-between; gap: 12pt; }
.logo { max-width: 92pt; max-height: 35pt; object-fit: contain; }
h1 { font-size: 23pt; line-height: 1.1; letter-spacing: -.6pt; margin: 11pt 0 5pt; }
.subtitle { font-size: 11pt; margin: 0 0 7pt; color: #4e6458; }
.meta { margin: 0; font-size: 8.8pt; color: #5b6861; overflow-wrap: anywhere; }
.summary { display: flex; gap: 0; margin: 0 0 12pt; border-top: 1px solid #d3ddd6; border-bottom: 1px solid #d3ddd6; padding: 10pt 0; break-inside: avoid; }
.kpi { flex: 1; padding: 0 12pt; border-left: 1px solid #d3ddd6; }
.kpi:first-child { padding-left: 0; border-left: 0; }
.value { font-size: 25pt; font-weight: bold; line-height: 1.15; color: #215d3f; }
.label { margin-top: 3pt; font-weight: bold; font-size: 10pt; }
.caption { margin-top: 3pt; font-size: 8pt; color: #617168; }
.note { padding: 8pt 10pt; background: #f4f6f3; border-left: 3px solid #779382; margin-bottom: 12pt; font-size: 9pt; break-inside: avoid; }
.note--attention { background: #fff8ea; border-color: #b07a20; }
.note strong { font-weight: bold; }
h2 { margin: 0; font-size: 13pt; }
.table-heading { margin: 0 0 8pt; break-after: avoid; }
.table-heading p { margin: 3pt 0 0; font-size: 8pt; color: #617168; }
table { border-collapse: collapse; width: 100%; table-layout: fixed; font-size: 9pt; }
thead { display: table-header-group; break-inside: avoid; page-break-inside: avoid; }
th { border-top: 1.5px solid #366c50; border-bottom: 1px solid #bdcec2; background: #f1f5f1; color: #3e5447; font-size: 8pt; font-weight: bold; text-align: left; padding: 8pt 6pt; }
td { border-bottom: 1px solid #dce4de; padding: 7pt 6pt; vertical-align: top; }
tr { break-inside: avoid; page-break-inside: avoid; }
.teacher { width: 27%; }
.booked { width: 12%; }
.target { width: 12%; }
.offered { width: 15%; }
.action { width: 34%; }
.numeric { text-align: right; font-variant-numeric: tabular-nums; }
.name { font-weight: bold; overflow-wrap: anywhere; }
.secondary { font-size: 7.8pt; line-height: 1.4; color: #66736b; margin-top: 3pt; overflow-wrap: anywhere; }
.action-title { font-weight: bold; overflow-wrap: anywhere; }
.attention .action-title { color: #8d5c0b; }
.empty { padding: 18pt 6pt; color: #5b6861; }
.explanation { margin-top: 13pt; padding-top: 9pt; border-top: 1px solid #dce4de; font-size: 8pt; color: #5b6861; break-inside: avoid; }
.explanation p { margin: 0 0 5pt; }
</style>
</head>
<body>
<header>
    <div class="brand">
        <div class="school"><?= html_escape($school_name ?? 'Forscherhaus') ?></div>
        <?php if (!empty($logo_data_url)): ?><img class="logo" src="<?= html_escape(
    $logo_data_url,
) ?>" alt="" /><?php endif; ?>
    </div>
    <h1>Buchungsstand</h1>
    <p class="subtitle">Elternsprechtag · Übersicht für die Schulleitung</p>
    <p class="meta"><?= html_escape($period_label ?? '') ?> · Stand <?= html_escape($generated_at_text ?? '') ?></p>
    <p class="meta">Auswahl: <?= html_escape($service_label ?? 'Alle Angebote') ?> · Status: <?= html_escape(
     $status_label ?? 'Gebucht',
 ) ?> · <?= html_escape($number(count($metrics))) ?> Lehrkräfte</p>
</header>
<section class="summary" aria-label="Zusammenfassung">
    <div class="kpi"><div class="value"><?= html_escape(
        $number($appointmentCount),
    ) ?></div><div class="label">Gebuchte Termine</div><div class="caption">Ausgewählte Buchungen</div></div>
    <div class="kpi"><div class="value"><?= html_escape(
        $number($requiredCount),
    ) ?></div><div class="label">Benötigte Termine</div><div class="caption"><?= html_escape(
    $requiredCaption,
) ?></div></div>
    <div class="kpi"><div class="value"><?= html_escape(
        $number($bookingAttention),
    ) ?></div><div class="label">Buchungsziel noch offen</div><div class="caption">Lehrkräfte mit festgelegtem Ziel</div></div>
</section>
<div class="note <?= $offerAttention || $bookingAttention ? 'note--attention' : '' ?>">
    <?php if ($offerAttention > 0): ?>
        <strong>Zuerst das Terminangebot klären.</strong> <?= html_escape(
            $number($offerAttention),
        ) ?> Lehrkräfte mit Lücke oder unklarem Angebot; Details stehen in der Tabelle.
    <?php elseif ($bookingAttention > 0): ?>
        <strong>Den Buchungsfortschritt nachhalten.</strong> Bei <?= html_escape(
            $number($bookingAttention),
        ) ?> Lehrkräften liegt die Buchungszahl noch unter dem gewählten Ziel. Ob jetzt eine Erinnerung sinnvoll ist, hängt von der laufenden Buchungsphase ab.
    <?php elseif ($metrics === []): ?>
        <strong>Keine Daten für diese Auswahl.</strong> Zeitraum und Filter prüfen. Das ist kein Nachweis, dass alle Buchungen erledigt sind.
    <?php else: ?>
        <strong>Weitere Hinweise in der Tabelle beachten.</strong> Ohne festgelegte Klassengröße wird kein Klassenziel bewertet. Die Zahlen ersetzen keine Prüfung, welche Familien noch erreicht werden müssen.
    <?php endif; ?>
</div>
<div class="table-heading"><h2>Stand je Lehrkraft</h2><p>Hinweise zuerst · Die Bezeichnungen entsprechen den hinterlegten Lehrkräften.</p></div>
<table>
    <thead><tr><th class="teacher">Lehrkraft / Lerngruppe</th><th class="booked numeric">Gebuchte<br>Termine</th><th class="target numeric">Benötigte<br>Termine</th><th class="offered numeric">Angebot /<br>erforderlich</th><th class="action">Nächster Schritt</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr class="<?= $row['offerIssue'] || $row['bookingIssue'] || $row['lateIssue'] ? 'attention' : '' ?>">
            <td><div class="name"><?= html_escape($row['metric']['provider_name'] ?? '') ?></div><?php if (
    !$row['explicit']
): ?><div class="secondary">Ohne festgelegte Klassengröße</div><?php endif; ?></td>
            <td class="numeric"><?= html_escape($number($row['booked'])) ?></td>
            <td class="numeric"><?= html_escape($number($row['explicit'] ? $row['target'] : null)) ?></td>
            <td class="numeric"><?= html_escape($number($row['planned'])) ?> / <?= html_escape(
     $number($row['required']),
 ) ?></td>
            <td><div class="action-title"><?= html_escape($row['action']) ?></div><?php if (
    $row['detail'] !== ''
): ?><div class="secondary"><?= html_escape($row['detail']) ?></div><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (
        $rows === []
    ): ?><tr><td colspan="5" class="empty">Keine Lehrkräfte in der aktuellen Auswahl.</td></tr><?php endif; ?>
    </tbody>
</table>
<div class="explanation">
    <p>Termine zählen Buchungen. Benötigte Termine entsprechen den hinterlegten Klassengrößen: ein Termin je Schülerin oder Schüler. Das Angebot folgt der bestehenden Kapazitätsplanung. „—“ bedeutet: nicht verfügbar oder nicht festgelegt. Alle Werte gelten für die aktuelle Auswahl. Den Zeitpunkt einer Erinnerung entscheidet die Schulleitung.</p>
</div>
</body>
</html>
