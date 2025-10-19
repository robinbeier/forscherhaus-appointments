<!doctype html>
<html lang="de">
    <head>
        <meta charset="utf-8" />
        <title>Schulleitungsreport &ndash; <?= html_escape($school_name ?? 'Forscherhaus') ?></title>
        <meta name="viewport" content="width=device-width,initial-scale=1" />
        <style>
            :root {
                --accent: #2563eb;
                --accent-weak: #dbeafe;
                --ink: #111827;
                --ink-muted: #6b7280;
                --border: #e5e7eb;
                --background: #f8fafc;
                --card: #ffffff;
                --warn: #b45309;
                --warn-bg: #fff7ed;
                --radius: 10pt;
            }

            @page {
                size: A4;
                margin: 12mm 12mm 14mm;
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
                gap: 18pt;
                min-height: calc(297mm - 26mm);
            }

            .header {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 12pt;
            }

            .header__titles {
                display: flex;
                flex-direction: column;
                gap: 4pt;
            }

            .header__title {
                margin: 0;
                font-size: 21pt;
                font-weight: 600;
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

            .summary-grid {
                display: grid;
                grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
                gap: 14pt;
                align-items: start;
            }

            .kpis {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12pt;
            }

            .card {
                background: var(--card);
                border: 1px solid var(--border);
                border-radius: var(--radius);
                padding: 12pt;
                display: flex;
                flex-direction: column;
                gap: 10pt;
            }

            .card h3 {
                margin: 0;
                font-size: 11pt;
                font-weight: 600;
            }

            .donut {
                display: flex;
                align-items: center;
                gap: 10pt;
            }

            .donut__figure {
                position: relative;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .donut__image {
                width: 120px;
                height: 120px;
                display: block;
            }

            .donut__figure--fallback {
                width: 120px;
                height: 120px;
                border-radius: 50%;
                border: 10px solid var(--accent-weak);
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .donut__label {
                position: absolute;
                font-size: 16pt;
                font-weight: 700;
                color: var(--ink);
            }

            .donut__stats {
                display: flex;
                flex-direction: column;
                gap: 4pt;
            }

            .donut__value {
                font-size: 16pt;
                font-weight: 700;
            }

            .donut__caption {
                margin: 0;
                color: var(--ink-muted);
                font-size: 9pt;
            }

            .meta-card {
                display: flex;
                align-items: baseline;
                justify-content: space-between;
                margin-top: 12pt;
                padding: 10pt 12pt;
                border-radius: var(--radius);
                border: 1px dashed var(--border);
                font-size: 9.5pt;
                color: var(--ink-muted);
            }

            .meta-card strong {
                font-size: 14pt;
                font-weight: 600;
                color: var(--ink);
            }

            .callout {
                border: 1px solid var(--warn);
                background: var(--warn-bg);
                border-radius: var(--radius);
                padding: 14pt;
                display: flex;
                flex-direction: column;
                gap: 8pt;
            }

            .callout h3 {
                margin: 0;
                font-size: 11pt;
                font-weight: 600;
                color: var(--warn);
            }

            .callout p {
                margin: 0;
                font-size: 9.5pt;
                color: var(--ink);
            }

            .callout ul {
                margin: 4pt 0 0;
                padding-left: 16pt;
                font-size: 9.5pt;
            }

            .callout li {
                margin-bottom: 4pt;
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
                background: var(--accent-weak);
                color: var(--ink);
                font-weight: 600;
                padding: 8pt 10pt;
                text-align: left;
                border-bottom: 1px solid var(--border);
            }

            tbody td {
                padding: 8pt 10pt;
                border-bottom: 1px solid var(--border);
                color: var(--ink);
            }

            tbody tr:last-child td {
                border-bottom: none;
            }

            tbody tr {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            tbody tr.alert td {
                background: rgba(37, 99, 235, 0.08);
            }

            .col-right {
                text-align: right;
            }

            .nowrap {
                white-space: nowrap;
            }

            .provider {
                margin: 0;
                font-weight: 600;
            }

            .provider__meta {
                margin-top: 3pt;
                font-size: 8.8pt;
                color: var(--ink-muted);
            }

            .bar {
                margin-top: 6pt;
                height: 8px;
                background: #f3f4f6;
                border-radius: 999px;
                overflow: hidden;
            }

            .bar__fill {
                height: 100%;
                background: var(--accent);
            }

            .badge {
                display: inline-flex;
                align-items: center;
                gap: 6pt;
                padding: 2pt 8pt;
                border-radius: 9999px;
                font-size: 9pt;
                font-weight: 600;
                border: 1px solid var(--border);
            }

            .badge--accent {
                color: var(--accent);
                background: rgba(37, 99, 235, 0.12);
                border-color: rgba(37, 99, 235, 0.32);
            }

            .badge--warn {
                color: var(--warn);
                background: var(--warn-bg);
                border-color: rgba(180, 83, 9, 0.4);
            }

            .badge--warn::before {
                content: '•';
                font-size: 12pt;
                line-height: 1;
                color: var(--warn);
            }

            .badge--muted {
                color: var(--ink-muted);
                background: #f3f4f6;
                border-color: var(--border);
            }

            .legend {
                font-size: 8.8pt;
                color: var(--ink-muted);
                margin-top: 6pt;
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

            $statusVariant = 'accent';
            $statusLabel = 'Im Ziel';

            if (!empty($metric['is_zero_target'])) {
                $statusVariant = 'muted';
                $statusLabel = 'Kein Ziel gepflegt';
            } elseif ($gapToThreshold > 0) {
                $statusVariant = 'warn';
                $statusLabel = 'Unter ' . number_format($thresholdRatio * 100, 0, ',', '.') . ' %';
            } elseif ($openRaw <= 0 && $targetRaw > 0) {
                $statusVariant = 'accent';
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
                'status_variant' => $statusVariant,
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
        $gapTotalFormatted = number_format($gapTotal, 0, ',', '.');

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
            'background' => '#e5e7eb',
            'foreground' => '#2563eb',
        ]);

        $progressInTarget = $teachersTotal > 0 ? max(0.0, min(1.0, $inTargetCount / $teachersTotal)) : 0.0;

        $inTargetDonutImage = donut_image_data_url($progressInTarget, $donutImageSize, $donutImageThickness, [
            'background' => '#e5e7eb',
            'foreground' => '#2563eb',
        ]);
        ?>
        <div class="page">
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

            <section class="summary-grid" aria-label="Kennzahlenübersicht">
                <div>
                    <div class="kpis">
                        <article class="card" aria-label="Gesamtauslastung">
                            <h3>Gesamtauslastung</h3>
                            <div class="donut">
                                <?php if ($primaryDonutImage !== null): ?>
                                    <div class="donut__figure">
                                        <img src="<?= html_escape($primaryDonutImage) ?>" alt="" class="donut__image" />
                                        <span class="donut__label"><?= html_escape(
                                            $summary['fill_rate_formatted'] ?? '0 %',
                                        ) ?></span>
                                    </div>
                                <?php else: ?>
                                    <div class="donut__figure donut__figure--fallback">
                                        <span class="donut__label"><?= html_escape(
                                            $summary['fill_rate_formatted'] ?? '0 %',
                                        ) ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="donut__stats">
                                    <div class="donut__value"><?= html_escape(
                                        $summary['fill_rate_formatted'] ?? '0 %',
                                    ) ?></div>
                                    <p class="donut__caption">
                                        <?= html_escape($bookedDistinctFormatted) ?> von <?= html_escape(
     $targetTotalFormatted,
 ) ?>
                                        Haushalten erreicht
                                    </p>
                                </div>
                            </div>
                        </article>

                        <article class="card" aria-label="Lehrkräfte im Zielkorridor">
                            <h3>Lehrkräfte ≥ <?= html_escape($threshold_percent ?? '75 %') ?></h3>
                            <div class="donut">
                                <?php if ($inTargetDonutImage !== null): ?>
                                    <div class="donut__figure">
                                        <img src="<?= html_escape(
                                            $inTargetDonutImage,
                                        ) ?>" alt="" class="donut__image" />
                                        <span class="donut__label"><?= html_escape(
                                            number_format($progressInTarget * 100, 0, ',', '.'),
                                        ) ?> %</span>
                                    </div>
                                <?php else: ?>
                                    <div class="donut__figure donut__figure--fallback">
                                        <span class="donut__label"><?= html_escape(
                                            number_format($progressInTarget * 100, 0, ',', '.'),
                                        ) ?> %</span>
                                    </div>
                                <?php endif; ?>
                                <div class="donut__stats">
                                    <div class="donut__value"><?= html_escape(
                                        number_format($progressInTarget * 100, 0, ',', '.'),
                                    ) ?> %</div>
                                    <p class="donut__caption"><?= html_escape($inTargetLabel) ?></p>
                                </div>
                            </div>
                        </article>
                    </div>

                    <div class="meta-card">
                        <span>Fehlend bis <?= html_escape($threshold_percent ?? '75 %') ?></span>
                        <strong><?= html_escape($gapTotalFormatted) ?></strong>
                    </div>
                </div>

                <aside class="callout">
                    <h3>Handlungsbedarf</h3>
                    <p>
                        <strong><?= html_escape(number_format($belowCount, 0, ',', '.')) ?></strong>
                        von <?= html_escape(number_format($teachersTotal, 0, ',', '.')) ?>
                        Lehrkräften liegen unter <?= html_escape($threshold_percent ?? '75 %') ?>.
                    </p>
                    <p>
                        Es fehlen insgesamt
                        <strong><?= html_escape($gapTotalFormatted) ?></strong> Buchungen bis zur Schwelle.
                    </p>
                    <?php if (!empty($topInterventions)): ?>
                        <ul>
                            <?php foreach ($topInterventions as $intervention): ?>
                                <li>
                                    <?= html_escape($intervention['provider_name'] ?? '') ?>
                                    &ndash;
                                    <strong><?= html_escape(
                                        $intervention['gap_to_threshold_formatted'] ??
                                            $formatNumber((int) ($intervention['gap_to_threshold'] ?? 0)),
                                    ) ?></strong>
                                    offen
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p>Empfehlung: Priorisierte Elternansprache bis zur Schwelle von <?= html_escape(
                            $threshold_percent ?? '75 %',
                        ) ?>.</p>
                    <?php else: ?>
                        <p>Alle Lehrkräfte liegen aktuell im Zielkorridor.</p>
                    <?php endif; ?>
                </aside>
            </section>

            <section class="table-wrapper" aria-label="Auslastung je Lehrkraft">
                <?php if (!empty($preparedMetrics)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Lehrkraft</th>
                                <th class="col-right">Klassengröße</th>
                                <th class="col-right">Gebucht</th>
                                <th>Auslastung</th>
                                <th class="col-right">bis <?= html_escape($threshold_percent ?? '75 %') ?></th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preparedMetrics as $metric): ?>
                                <?php
                                $rowClass = !empty($metric['needs_attention']) ? ' class="alert"' : '';
                                $fillPercent = (int) (!empty($metric['is_zero_target'])
                                    ? 0
                                    : max(0, min(100, (int) ($metric['fill_rate_percent_value'] ?? 0))));
                                ?>
                                <tr<?= $rowClass ?>>
                                    <td>
                                        <p class="provider"><?= html_escape($metric['provider_name'] ?? '') ?></p>
                                        <?php if (!empty($metric['status_secondary'])): ?>
                                            <div class="provider__meta"><?= html_escape(
                                                $metric['status_secondary'],
                                            ) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-right">
                                        <?= !empty($metric['is_zero_target'])
                                            ? '&mdash;'
                                            : html_escape($metric['target'] ?? '0') ?>
                                    </td>
                                    <td class="col-right"><?= html_escape($metric['booked'] ?? '0') ?></td>
                                    <td>
                                        <span><?= html_escape($metric['fill_rate_percent'] ?? '0 %') ?></span>
                                        <div class="bar" role="presentation">
                                            <div class="bar__fill" style="width: <?= $fillPercent ?>%;"></div>
                                        </div>
                                    </td>
                                    <td class="col-right nowrap">
                                        <?= (int) ($metric['gap_to_threshold'] ?? 0) > 0
                                            ? html_escape(
                                                $metric['gap_to_threshold_formatted'] ??
                                                    (string) (int) ($metric['gap_to_threshold'] ?? 0),
                                            )
                                            : '&mdash;' ?>
                                    </td>
                                    <td>
                                        <span class="badge badge--<?= html_escape($metric['status_variant']) ?>">
                                            <?= html_escape($metric['status_label']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="legend">Keine Daten für die ausgewählten Filter vorhanden.</p>
                <?php endif; ?>
            </section>

            <p class="legend">
                bis <?= html_escape(
                    $threshold_percent ?? '75 %',
                ) ?> = Anzahl benötigter Buchungen, um den Zielwert zu erreichen.
            </p>
        </div>
        <script>
            window.chartsReady = true;
        </script>
    </body>
</html>
