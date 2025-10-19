<!doctype html>
<html lang="de">
    <head>
        <meta charset="utf-8" />
        <title>Schulleitungsreport &ndash; <?= html_escape($school_name ?? 'Forscherhaus') ?></title>
        <meta name="viewport" content="width=device-width,initial-scale=1" />
        <style>
            :root {
                --brand: #2e7d32;
                --brand-muted: #c8e6c9;
                --ink: #1f2933;
                --ink-muted: #475467;
                --border: #dfe3eb;
                --border-strong: #ccd2db;
                --background: #f6f8fb;
                --background-strong: #e9eef5;
                --attention: #ab1f1f;
                --attention-light: #fce8e8;
                --shadow: rgba(15, 23, 42, 0.07);
                --radius: 12px;
                --warning: #b45309;
                --warning-light: #fef3c7;
            }

            @page {
                size: A4;
                margin: 16mm 18mm 18mm;
            }

            html {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                font-size: 10pt;
            }

            body {
                margin: 0;
                padding: 0;
                color: var(--ink);
                font-family:
                    'Inter',
                    system-ui,
                    -apple-system,
                    'Segoe UI',
                    Roboto,
                    'Helvetica Neue',
                    Arial,
                    'Noto Sans',
                    sans-serif;
                line-height: 1.5;
                background: #fff;
            }

            .page {
                display: flex;
                flex-direction: column;
                min-height: calc(297mm - 34mm);
                gap: 16pt;
            }

            .header {
                display: flex;
                gap: 12pt;
                align-items: flex-start;
                justify-content: space-between;
            }

            .header__titles {
                display: flex;
                flex-direction: column;
                gap: 4pt;
            }

            .header__title {
                font-size: 21pt;
                font-weight: 600;
                margin: 0;
            }

            .header__meta {
                margin: 0;
                font-size: 9.5pt;
                color: var(--ink-muted);
            }

            .logo {
                width: 120px;
                max-height: 64px;
                object-fit: contain;
            }

            .header__logo {
                margin-left: auto;
                display: block;
            }

            .kpi-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180pt, 1fr));
                gap: 12pt;
            }

            .kpi {
                padding: 12pt;
                border: 1px solid var(--border);
                border-radius: var(--radius);
                background: #fff;
                box-shadow: 0 1px 2px var(--shadow);
            }
                .kpi__label { margin: 0 0 6pt; font-size: 11pt; }
                .kpi__chart { display: flex; align-items: center; gap: 12pt; }
                .kpi__meta { margin: 0; font-size: 9pt; color: var(--ink-muted); }

            .donut-figure {
                position: relative;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .donut-figure__image {
                display: block;
                width: 72px;
                height: 72px;
            }

            .donut-figure__label {
                position: absolute;
                font-size: 11pt;
                font-weight: 600;
                color: var(--ink);
            }

            .donut-figure--fallback {
                width: 72px;
                height: 72px;
                border-radius: 50%;
                border: 8px solid #e9eef5;
                background: #fff;
            }

            /* Hinweis-/Callout-Box im Stil der Eltern-PDF (nur als Warnhinweis getönt) */
            .callout {
                display: flex; gap: 10pt; align-items: flex-start;
                padding: 10pt 12pt; border-radius: var(--radius);
                border: 1px solid var(--warning); background: var(--warning-light);
            }
                .callout__icon { width: 14pt; height: 14pt; margin-top: 2pt; }
                .callout__title { margin: 0 0 2pt; font-size: 10.5pt; font-weight: 600; }
                .callout__text { margin: 0; font-size: 9.5pt; }

            /* Tabelle: neue „bis 75 %“-Spalte + Sekundärhinweis im Status */
            .table__hint { margin-top: 3pt; font-size: 8.5pt; color: var(--ink-muted); }

            /* Zeilen farblich ganz leicht akzentuieren, wenn < 75 % */
            .table__row--alert td { background: rgba(220, 38, 38, 0.05); }

            /* Kein Zeilenumbruch bei Prozent/Chips */
            .nowrap { white-space: nowrap; }

            .actions-grid {
                display: grid;
                grid-template-columns: 2fr 3fr;
                gap: 12pt;
            }

            .card {
                border: 1px solid var(--border);
                border-radius: var(--radius);
                background: #fff;
                padding: 12pt;
                display: flex;
                flex-direction: column;
                gap: 8pt;
            }

            .card__title {
                margin: 0;
                font-size: 11pt;
                font-weight: 600;
            }

            .card__list {
                margin: 0;
                padding-left: 12pt;
                font-size: 9.5pt;
            }

            .card__list li {
                margin-bottom: 4pt;
            }

            .card__meta {
                font-size: 9pt;
                color: var(--ink-muted);
                margin: 0;
            }

            .table-wrapper {
                border: 1px solid var(--border);
                border-radius: var(--radius);
                overflow: hidden;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 9.8pt;
                font-variant-numeric: tabular-nums;
            }

            thead th {
                background: var(--background-strong);
                color: var(--ink-muted);
                text-transform: uppercase;
                letter-spacing: 0.3pt;
                font-size: 8.8pt;
                font-weight: 600;
                padding: 7pt 9pt;
                text-align: left;
            }

            tbody td {
                padding: 8pt 9pt;
                border-bottom: 1px solid var(--border);
                vertical-align: top;
            }

            tbody tr:last-child td {
                border-bottom: none;
            }

            .table__row--alert {
                background: var(--attention-light);
            }

            .table__row--fallback {
                background-image: linear-gradient(
                    135deg,
                    rgba(0, 0, 0, 0.04) 25%,
                    rgba(0, 0, 0, 0) 25%
                );
                background-size: 12pt 12pt;
            }

            .provider {
                font-weight: 600;
                margin: 0;
            }

            .status-chip {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 3pt 8pt;
                border-radius: 16pt;
                font-size: 8.5pt;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.3pt;
                border: 1px solid transparent;
            }

            .status-chip--alert {
                background: var(--attention-light);
                border-color: var(--attention);
                color: var(--attention);
            }

            .status-chip--ok {
                background: rgba(46, 125, 50, 0.12);
                border-color: rgba(46, 125, 50, 0.32);
                color: var(--brand);
            }

            .status-chip--brand {
                background: var(--brand);
                color: #fff;
            }

            .status-chip--muted {
                background: var(--background-strong);
                border-color: var(--border-strong);
                color: var(--ink-muted);
            }

            .table__number {
                text-align: right;
            }

            .table__progress {
                margin-top: 5pt;
                height: 6pt;
                background: var(--background-strong);
                border-radius: 999px;
                overflow: hidden;
            }

            .table__progress-bar {
                height: 100%;
                background: var(--brand);
            }

            .table__progress-bar.is-alert {
                background: var(--attention);
            }

            .empty-state {
                padding: 18pt;
                text-align: center;
                color: var(--ink-muted);
                font-size: 11pt;
            }

        </style>
    </head>
    <body>
        <?php
        $formatNumber = static function (int $value): string {
            return number_format($value, 0, ',', '.');
        };

        $thresholdRatio = isset($threshold_ratio) ? (float) $threshold_ratio : 0.75;
        $metrics = $metrics ?? [];
        $preparedMetrics = [];

        foreach ($metrics as $metric) {
            $targetRaw = (int) ($metric['target_raw'] ?? 0);
            $bookedRaw = (int) ($metric['booked_raw'] ?? 0);
            $openRaw = (int) ($metric['open_raw'] ?? 0);
            $gapToThreshold =
                (int) ($metric['gap_to_threshold'] ?? max((int) ceil($thresholdRatio * $targetRaw) - $bookedRaw, 0));
            $thresholdAbsolute = (int) ($metric['threshold_absolute'] ?? (int) ceil($thresholdRatio * $targetRaw));

            $statusTone = 'ok';
            $statusLabel = 'Im Ziel';

            if (!empty($metric['is_zero_target'])) {
                $statusTone = 'muted';
                $statusLabel = 'Kein Ziel gepflegt';
            } elseif ($gapToThreshold > 0) {
                $statusTone = 'alert';
                $statusLabel = 'Unter ' . number_format($thresholdRatio * 100, 0, ',', '.') . ' %';
            } elseif ($openRaw <= 0 && $targetRaw > 0) {
                $statusTone = 'brand';
                $statusLabel = 'Voll ausgelastet';
            }

            $secondaryLabel = null;

            if (!empty($metric['is_target_fallback'])) {
                $secondaryLabel = $metric['target_origin_label'] ?? 'Fallback';
            } elseif (!empty($metric['has_plan'])) {
                $secondaryLabel = 'Unterrichtsplan hinterlegt';
            }

            $preparedMetrics[] = array_merge($metric, [
                'target_raw' => $targetRaw,
                'booked_raw' => $bookedRaw,
                'open_raw' => $openRaw,
                'gap_to_threshold' => $gapToThreshold,
                'gap_to_threshold_formatted' => $formatNumber($gapToThreshold),
                'threshold_absolute' => $thresholdAbsolute,
                'status_tone' => $statusTone,
                'status_label' => $statusLabel,
                'status_secondary' => $secondaryLabel,
            ]);
        }

        usort($preparedMetrics, static function (array $left, array $right): int {
            $gapSort = $right['gap_to_threshold'] <=> $left['gap_to_threshold'];

            if ($gapSort !== 0) {
                return $gapSort;
            }

            return $left['fill_rate'] <=> $right['fill_rate'];
        });

        $teachersTotal = count($preparedMetrics);
        $belowCount = count(
            array_filter($preparedMetrics, static fn(array $m): bool => (int) ($m['gap_to_threshold'] ?? 0) > 0),
        );
        $inTargetCount = $teachersTotal - $belowCount;
        $gapTotal = array_sum(
            array_map(static fn(array $m): int => (int) ($m['gap_to_threshold'] ?? 0), $preparedMetrics),
        );

        $inTargetLabel =
            number_format($inTargetCount, 0, ',', '.') .
            ' / ' .
            number_format($teachersTotal, 0, ',', '.') .
            ' im Ziel';
        $gapTotalLabel = number_format($gapTotal, 0, ',', '.') . ' bis ' . ($threshold_percent ?? '75 %');

        $topInterventions = array_slice(
            array_filter($preparedMetrics, static fn(array $metric): bool => $metric['gap_to_threshold'] > 0),
            0,
            3,
        );

        $bookedDistinctFormatted =
            $summary['booked_distinct_total_formatted'] ?? ($summary['booked_total_formatted'] ?? '0');
        $targetTotalFormatted = $summary['target_total_formatted'] ?? '0';
        $fillRateValue = (float) ($summary['fill_rate'] ?? 0);

        $donutImageSize = 120;
        $donutImageThickness = 20;

        $primaryDonutImage = donut_image_data_url($fillRateValue, $donutImageSize, $donutImageThickness, [
            'background' => '#e9eef5',
            'foreground' => '#2e7d32',
        ]);

        $progressInTarget = $teachersTotal > 0 ? max(0.0, min(1.0, $inTargetCount / $teachersTotal)) : 0.0;

        $inTargetDonutImage = donut_image_data_url($progressInTarget, $donutImageSize, $donutImageThickness, [
            'background' => '#e9eef5',
            'foreground' => '#2e7d32',
        ]);
        ?>
        <div class="page page--front">
            <header class="header" role="banner">
                <div class="header__titles">
                    <h1 class="header__title">Schulleitungsreport</h1>
                    <p class="header__meta">
                        Übersicht zu den Klassenleitungssprechtagen im Zeitraum
                        <?= html_escape($period_label ?? '') ?>
                    </p>
                </div>
                <?php if (!empty($logo_data_url)): ?>
                    <img src="<?= html_escape($logo_data_url) ?>" alt="<?= html_escape(
    $school_name ?? '',
) ?>" class="logo header__logo" />
                <?php endif; ?>
            </header>

            <section class="kpi-grid" aria-label="Kennzahlenübersicht">
                <article class="kpi">
                    <h2 class="kpi__label">Gesamtauslastung</h2>
                    <div class="kpi__chart">
                        <?php if ($primaryDonutImage !== null): ?>
                            <div class="donut-figure">
                                <img src="<?= html_escape($primaryDonutImage) ?>" alt="" class="donut-figure__image" />
                                <span class="donut-figure__label"><?= html_escape(
                                    $summary['fill_rate_formatted'] ?? '0 %',
                                ) ?></span>
                            </div>
                        <?php else: ?>
                            <div class="donut-figure donut-figure--fallback">
                                <span class="donut-figure__label"><?= html_escape(
                                    $summary['fill_rate_formatted'] ?? '0 %',
                                ) ?></span>
                            </div>
                        <?php endif; ?>
                        <p class="kpi__meta">
                            <?= html_escape($bookedDistinctFormatted) ?> von <?= html_escape($targetTotalFormatted) ?>
                            Haushalten erreicht
                        </p>
                    </div>
                </article>
            </section>

        <!-- Kachel: Lehrkräfte ≥ 75 % -->
        <article class="kpi">
        <h2 class="kpi__label">Lehrkräfte ≥ <?= html_escape($threshold_percent ?? '75 %') ?></h2>
                    <div class="kpi__chart">
            <?php if ($inTargetDonutImage !== null): ?>
                <div class="donut-figure">
                    <img src="<?= html_escape($inTargetDonutImage) ?>" alt="" class="donut-figure__image" />
                    <span class="donut-figure__label"><?= html_escape(
                        number_format($progressInTarget * 100, 0, ',', '.'),
                    ) ?> %</span>
                </div>
            <?php else: ?>
                <div class="donut-figure donut-figure--fallback">
                    <span class="donut-figure__label"><?= html_escape(
                        number_format($progressInTarget * 100, 0, ',', '.'),
                    ) ?> %</span>
                </div>
            <?php endif; ?>
            <p class="kpi__meta"><?= html_escape($inTargetLabel) ?></p>
        </div>
        </article>

        <!-- Kachel: Fehlend bis 75 % (gesamt) -->
        <article class="kpi">
        <h2 class="kpi__label">Fehlend bis <?= html_escape($threshold_percent ?? '75 %') ?> (gesamt)</h2>
        <div class="kpi__chart">
            <svg width="0" height="0" aria-hidden="true"></svg>
            <p class="kpi__meta" style="font-size: 16pt; font-weight: 600; margin: 0;">
            <?= html_escape($gapTotalLabel) ?>
            </p>
        </div>
        </article>

        <section aria-label="Handlungsbedarf" class="callout">
        <!-- kleines Warnicon -->
        <svg class="callout__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
            <circle cx="12" cy="12" r="10" stroke-width="1.5"></circle>
            <line x1="12" y1="7" x2="12" y2="13" stroke-width="1.5"></line>
            <circle cx="12" cy="17" r="1.2" fill="currentColor"></circle>
        </svg>
        <div class="callout__content">
            <p class="callout__title">Handlungsbedarf</p>
            <p class="callout__text">
            <strong><?= html_escape(number_format($belowCount, 0, ',', '.')) ?></strong> von
            <?= html_escape(number_format($teachersTotal, 0, ',', '.')) ?> Lehrkräften liegen unter
            <?= html_escape($threshold_percent ?? '75 %') ?>. Insgesamt fehlen
            <strong><?= html_escape(number_format($gapTotal, 0, ',', '.')) ?></strong> Buchungen bis zur Schwelle.
            Bitte priorisieren Sie die Top 3 unten.
            </p>
        </div>
        </section>


            <section class="actions-grid" aria-label="Handlungsempfehlungen">
                <article class="card">
                    <?php if (!empty($topInterventions)): ?>
                        <h4 class="card__title">Top 3 Lehrkräfte mit größtem Bedarf</h4>
                        <ol class="card__list">
                            <?php foreach ($topInterventions as $intervention): ?>
                                <li>
                                    <?= html_escape($intervention['provider_name'] ?? '') ?>:
                                    <?= html_escape(
                                        $intervention['gap_to_threshold_formatted'] ??
                                            $formatNumber((int) ($intervention['gap_to_threshold'] ?? 0)),
                                    ) ?>
                                    fehlend
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php else: ?>
                        <p class="card__meta">Alle Lehrkräfte liegen aktuell im Zielkorridor.</p>
                    <?php endif; ?>
                    <p class="card__meta">Empfehlung: Priorisierte Elternansprache bis zur Schwelle von <?= html_escape(
                        $threshold_percent ?? '75 %',
                    ) ?>.</p>
                </article>
            </section>

            <section class="table-wrapper" aria-label="Auslastung je Lehrkraft">
                <table>
                    <thead>
                    <tr>
                        <th style="width: 30%;">Lehrkraft</th>
                        <th style="width: 14%;" class="table__number">Klassengröße</th>
                        <th style="width: 14%;" class="table__number">Gebucht</th>
                        <th style="width: 16%;">Auslastung</th>
                        <th style="width: 12%;" class="table__number">bis&nbsp;75&nbsp;%</th>
                        <th style="width: 14%;">Status</th>
                    </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($preparedMetrics as $metric): ?>
                            <?php
                            $rowClasses = [];

                            if (!empty($metric['needs_attention'])) {
                                $rowClasses[] = 'table__row--alert';
                            }

                            if (!empty($metric['is_target_fallback'])) {
                                $rowClasses[] = 'table__row--fallback';
                            }

                            $rowClass = implode(' ', $rowClasses);
                            ?>
                            <tr<?= $rowClass ? ' class="' . html_escape($rowClass) . '"' : '' ?>>
                                <td>
                                    <p class="provider"><?= html_escape($metric['provider_name'] ?? '') ?></p>
                                </td>
                                <td class="table__number">
                                    <?= !empty($metric['is_zero_target'])
                                        ? '&mdash;'
                                        : html_escape($metric['target'] ?? '0') ?>
                                </td>
                                <td class="table__number"><?= html_escape($metric['booked'] ?? '0') ?></td>
                                <td>
                                    <div><?= html_escape($metric['fill_rate_percent'] ?? '0 %') ?></div>
                                    <div class="table__progress" role="presentation">
                                        <div
                                            class="table__progress-bar<?= !empty($metric['needs_attention'])
                                                ? ' is-alert'
                                                : '' ?>"
                                            style="width: <?= (int) ($metric['is_zero_target']
                                                ? 0
                                                : $metric['fill_rate_percent_value'] ?? 0) ?>%;"
                                        ></div>
                                    </div>
                                </td>
                                <!-- NEU: bis 75 % -->
                                <td class="table__number nowrap">
                                <?= (int) ($metric['gap_to_threshold'] ?? 0) > 0
                                    ? html_escape(
                                        $metric['gap_to_threshold_formatted'] ??
                                            (string) (int) ($metric['gap_to_threshold'] ?? 0),
                                    )
                                    : '&mdash;' ?>
                                </td>

                                <td>
                                <span class="status-chip status-chip--<?= html_escape($metric['status_tone']) ?>">
                                    <?= html_escape($metric['status_label']) ?>
                                </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

        </div>
    </body>
</html>
