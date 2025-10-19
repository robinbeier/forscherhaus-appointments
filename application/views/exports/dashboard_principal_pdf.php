<!doctype html>
<html lang="de">
    <head>
        <meta charset="utf-8" />
        <title>Schulleitungsreport &ndash; <?= html_escape($school_name ?? 'Forscherhaus') ?></title>
        <meta name="viewport" content="width=device-width,initial-scale=1" />
        <style>
            :root {
                --brand: #2e7d32;
                --ink: #1f2933;
                --ink-muted: #475467;
                --border: #dfe3eb;
                --border-strong: #c8ced6;
                --background: #f7f9fc;
                --background-strong: #edf1f7;
                --attention: #c2410c;
                --attention-light: #fde68a;
                --success: #047857;
                --success-light: #dcfce7;
                --radius: 12px;
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
                gap: 14pt;
            }

            .header {
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                gap: 12pt;
                align-items: center;
            }

            .header__titles {
                display: flex;
                flex-direction: column;
                gap: 4pt;
            }

            .header__title {
                font-size: 20pt;
                font-weight: 600;
                margin: 0;
            }

            .header__subtitle {
                margin: 0;
                color: var(--ink-muted);
                font-size: 11pt;
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

            .meta {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 9pt;
                padding: 10pt 12pt;
                border: 1px solid var(--border);
                border-radius: var(--radius);
                background: var(--background);
            }

            .meta__item {
                display: flex;
                flex-direction: column;
                gap: 3pt;
            }

            .meta__label {
                font-size: 9pt;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.4pt;
                color: var(--ink-muted);
            }

            .meta__value {
                font-size: 11pt;
            }

            .kpi-grid {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 12pt;
            }

            .kpi {
                padding: 12pt;
                border: 1px solid var(--border);
                border-radius: var(--radius);
                background: #fff;
                display: flex;
                flex-direction: column;
                gap: 6pt;
            }

            .kpi__label {
                margin: 0;
                font-size: 9.5pt;
                color: var(--ink-muted);
                text-transform: uppercase;
                letter-spacing: 0.35pt;
            }

            .kpi__value {
                font-size: 18pt;
                font-weight: 600;
                margin: 0;
            }

            .kpi__detail {
                margin: 0;
                color: var(--ink-muted);
                font-size: 9.2pt;
            }

            .table {
                border: 1px solid var(--border);
                border-radius: var(--radius);
                overflow: hidden;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 10pt;
            }

            thead th {
                background: var(--background-strong);
                color: var(--ink-muted);
                text-transform: uppercase;
                letter-spacing: 0.3pt;
                font-size: 9pt;
                font-weight: 600;
                padding: 8pt 10pt;
                text-align: left;
                border-bottom: 1px solid var(--border);
            }

            tbody td {
                padding: 9pt 10pt;
                border-bottom: 1px solid var(--border);
                vertical-align: top;
            }

            tbody tr:last-child td {
                border-bottom: none;
            }

            .provider {
                font-weight: 600;
                margin: 0;
            }

            .provider-meta {
                margin-top: 4pt;
                font-size: 8.8pt;
                color: var(--ink-muted);
                display: flex;
                gap: 6pt;
                flex-wrap: wrap;
            }

            .badge {
                display: inline-flex;
                align-items: center;
                gap: 4pt;
                border-radius: 999px;
                padding: 2pt 7pt;
                font-size: 8pt;
                text-transform: uppercase;
                letter-spacing: 0.4pt;
                font-weight: 600;
            }

            .badge--attention {
                background: var(--attention-light);
                color: var(--attention);
            }

            .badge--fallback {
                background: var(--background-strong);
                color: var(--ink-muted);
            }

            .badge--plan {
                background: var(--success-light);
                color: var(--success);
            }

            .table__number {
                text-align: right;
                font-variant-numeric: tabular-nums;
            }

            .table__progress {
                margin-top: 6pt;
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

            .footnotes {
                margin-top: auto;
                font-size: 8.8pt;
                color: var(--ink-muted);
                line-height: 1.4;
            }

            .footnotes p {
                margin: 4pt 0;
            }
        </style>
    </head>
    <body>
        <div class="page">
            <header class="header" role="banner">
                <div class="header__titles">
                    <h1 class="header__title">Schulleitungsreport</h1>
                    <p class="header__subtitle"><?= html_escape($school_name ?? '') ?></p>
                    <p class="header__meta">
                        Zeitraum: <?= html_escape($period_label ?? '') ?> &bull; Filter: <?= html_escape(
     $service_label ?? '',
 ) ?> &bull; Status: <?= html_escape($status_label ?? '') ?>
                    </p>
                    <p class="header__meta">
                        Generiert am <?= html_escape($generated_at ?? '') ?>
                    </p>
                </div>
                <?php if (!empty($logo_data_url)): ?>
                    <img src="<?= html_escape($logo_data_url) ?>" alt="<?= html_escape(
    $school_name ?? '',
) ?>" class="logo" />
                <?php endif; ?>
            </header>

            <section class="meta" aria-label="Filterkontext">
                <div class="meta__item">
                    <div class="meta__label">Zeitraum</div>
                    <div class="meta__value"><?= html_escape($period_label ?? '') ?></div>
                </div>
                <div class="meta__item">
                    <div class="meta__label">Angebot</div>
                    <div class="meta__value"><?= html_escape($service_label ?? 'Alle Angebote') ?></div>
                </div>
                <div class="meta__item">
                    <div class="meta__label">Status</div>
                    <div class="meta__value"><?= html_escape($status_label ?? '') ?></div>
                </div>
            </section>

            <section class="kpi-grid" aria-label="Kennzahlen">
                <article class="kpi">
                    <h2 class="kpi__label">Gesamtauslastung</h2>
                    <p class="kpi__value"><?= html_escape($summary['fill_rate_formatted'] ?? '0 %') ?></p>
                    <p class="kpi__detail">
                        <?= html_escape(
                            ($summary['booked_total_formatted'] ?? '0') .
                                ' / ' .
                                ($summary['target_total_formatted'] ?? '0'),
                        ) ?> Plätze belegt
                    </p>
                </article>
                <article class="kpi">
                    <h2 class="kpi__label">Gebuchte Termine</h2>
                    <p class="kpi__value"><?= html_escape($summary['booked_total_formatted'] ?? '0') ?></p>
                    <p class="kpi__detail"><?= (int) ($summary['provider_count'] ?? 0) ?> Lehrkräfte im Zeitraum</p>
                </article>
                <article class="kpi">
                    <h2 class="kpi__label">Offene Plätze</h2>
                    <p class="kpi__value"><?= html_escape($summary['open_total_formatted'] ?? '0') ?></p>
                    <p class="kpi__detail">
                        <?= html_escape(
                            ($summary['explicit_target_count'] ?? 0) . ' KL mit Ziel',
                        ) ?> &middot; <?= html_escape(($summary['without_target_count'] ?? 0) . ' ohne') ?>
                    </p>
                </article>
                <article class="kpi">
                    <h2 class="kpi__label">Unter Schwelle</h2>
                    <p class="kpi__value"><?= html_escape((string) ($summary['attention_count'] ?? 0)) ?></p>
                    <p class="kpi__detail">Schwellenwert <?= html_escape($threshold_percent ?? '0 %') ?></p>
                </article>
            </section>

            <section class="table" aria-label="Auslastung Lehrkräfte">
                <?php if (!empty($metrics)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 32%;">Lehrkraft</th>
                                <th style="width: 16%;" class="table__number">Ziel</th>
                                <th style="width: 16%;" class="table__number">Gebucht</th>
                                <th style="width: 16%;" class="table__number">Offen</th>
                                <th style="width: 20%;">Auslastung</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($metrics as $metric): ?>
                                <tr>
                                    <td>
                                        <p class="provider"><?= html_escape($metric['provider_name'] ?? '') ?></p>
                                        <div class="provider-meta">
                                            <?php if (!empty($metric['needs_attention'])): ?>
                                                <span class="badge badge--attention">Unter Zielwert</span>
                                            <?php endif; ?>
                                            <?php if (!empty($metric['has_plan'])): ?>
                                                <span class="badge badge--plan">Unterrichtsplan</span>
                                            <?php endif; ?>
                                            <?php if (!empty($metric['is_target_fallback'])): ?>
                                                <span class="badge badge--fallback"><?= html_escape(
                                                    $metric['target_origin_label'] ?? 'Fallback',
                                                ) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($metric['is_zero_target'])): ?>
                                                <span class="badge badge--fallback">Kein Ziel definiert</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="table__number">
                                        <?= $metric['is_zero_target']
                                            ? '&mdash;'
                                            : html_escape($metric['target'] ?? '0') ?>
                                    </td>
                                    <td class="table__number"><?= html_escape($metric['booked'] ?? '0') ?></td>
                                    <td class="table__number"><?= html_escape($metric['open'] ?? '0') ?></td>
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
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">Für die ausgewählten Filter liegen keine Lehrkräfte-Daten vor.</div>
                <?php endif; ?>
            </section>

            <section class="footnotes">
                <p>Hinweis: Lehrkräfte werden nach aktueller Auslastung (niedrigster Wert zuerst) sortiert.</p>
                <p>
                    Klassengrößen stammen aus dem Stammdatensatz der Lehrkraft. Wenn kein Ziel hinterlegt ist, nutzt das
                    Dashboard die Kapazität der Planung als Fallback.
                </p>
            </section>
        </div>
    </body>
</html>
