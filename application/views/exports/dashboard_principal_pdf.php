<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8" />
<title>Schulleitungsreport – <?= html_escape($school_name ?? 'Forscherhaus') ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<style>
/* ===== Design Tokens ===== */
:root{
  --accent:#16A34A;
  --accent-weak:#DCFCE7;
  --ink:#111827;
  --ink-muted:#6B7280;
  --border:#E5E7EB;
  --background:#F8FAFC;
  --card:#FFFFFF;
  --warn:#B45309;
  --warn-bg:#FFF7ED;
  --risk:#B91C1C;
  --risk-bg:#FEF2F2;
  --radius:12pt;
}
@page{size:A4;margin:12mm 12mm 14mm;}
html{-webkit-print-color-adjust:exact;print-color-adjust:exact;font-size:10pt;}
body{margin:0;color:var(--ink);font-family:"Inter",system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif;background:#fff;line-height:1.45;}

/* ===== Layout ===== */
.page{height:calc(297mm - 26mm);display:grid;grid-template-rows:1fr auto;gap:10pt;padding:0 8pt;box-sizing:border-box;page-break-inside:avoid;break-inside:avoid;}
.page--break{page-break-before:always;}
.page__content{display:flex;flex-direction:column;gap:14pt;min-height:0;}
.header{display:flex;gap:10pt;align-items:flex-start;}
.header__titles{display:flex;flex-direction:column;gap:3pt;}
.header__title{margin:0;font-size:21pt;font-weight:600;}
.header__meta{margin:0;font-size:9.5pt;color:var(--ink-muted);}
.header__logo{margin-left:auto;display:block;}
.logo{width:120px;max-height:64px;object-fit:contain;}

.summary-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:14pt;align-items:start;}
.kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14pt;}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:12pt;display:flex;flex-direction:column;gap:10pt;}
.card h3{margin:0;font-size:11.5pt;font-weight:600;}

/* Donut */
.donut{display:grid;grid-template-columns:auto 1fr;gap:12pt;align-items:center;}
.donut__figure{position:relative;width:72pt;height:72pt;display:grid;place-items:center;}
.donut__image{width:100%;height:100%;}
.donut__figure--fallback{border-radius:50%;border:10px solid var(--accent-weak);display:flex;align-items:center;justify-content:center;}
.donut__label{position:absolute;font-size:16pt;font-weight:700;color:var(--ink);}
.donut__stats{display:flex;flex-direction:column;gap:4pt;}
.donut__value{font-size:16pt;font-weight:700;}
.donut__caption{margin:0;color:var(--ink-muted);font-size:9pt;}

/* KPI */
.kpi{display:flex;align-items:center;justify-content:space-between;gap:12pt;padding:10pt 12pt;border:1px solid var(--border);border-radius:10pt;font-size:9.8pt;color:var(--ink-muted);}
.kpi strong{font-size:18pt;font-weight:700;color:var(--ink);}

/* Callout */
.callout{background:var(--warn-bg);border:1px solid rgba(180,83,9,.35);border-radius:var(--radius);padding:12pt;display:flex;flex-direction:column;gap:6pt;}
.callout h3{margin:0;font-size:11.5pt;}
.callout p{margin:0;font-size:9.5pt;}
.callout ul{margin:6pt 0 0 18pt;padding:0;font-size:9.3pt;list-style:disc;}
.callout li{margin:0 0 4pt 0;padding-left:2pt;}
.callout__section-title{margin-top:6pt;font-size:9.1pt;font-weight:600;color:var(--ink);}

/* Pill */
.pill{display:inline-flex;align-items:center;gap:4pt;padding:1pt 8pt;border-radius:999px;font-size:8.8pt;font-weight:600;border:1px solid transparent;}
.pill--ok{color:var(--accent);background:var(--accent-weak);border-color:rgba(22,163,74,.35);}
.pill--warn{color:var(--warn);background:var(--warn-bg);border-color:rgba(180,83,9,.4);}
.pill--risk{color:var(--risk);background:var(--risk-bg);border-color:rgba(185,28,28,.3);}

/* Tabelle */
.table-card{padding:12pt 0 0 0;display:flex;flex-direction:column;min-height:0;}
table{width:100%;border-collapse:separate;border-spacing:0;font-size:9.8pt;margin-top:0;}
thead th{text-align:left;font-weight:600;font-size:9.6pt;color:var(--ink-muted);padding:10pt 12pt;border-bottom:1px solid var(--border);}
tbody td{padding:10pt 12pt;border-bottom:1px solid var(--border);vertical-align:middle;}
tbody tr:last-child td{border-bottom:none;}
.col-right{text-align:right;}
.col-size{width:10%;}
.col-booked{width:12%;}
.col-fill{width:14%;}
.col-after-15{width:12%;white-space:nowrap;}
.col-gap{width:10%;white-space:nowrap;}
.col-status{width:24%;}
.nowrap{white-space:nowrap;}
.provider{margin:0;font-weight:600;}
.provider__meta{margin:2pt 0 0 0;color:var(--ink-muted);font-size:8.8pt;display:flex;gap:6pt;align-items:center;flex-wrap:wrap;}
.provider__slots{color:inherit;}

/* Balken */
.bar{margin-top:6pt;height:8px;background:#F3F4F6;border-radius:999px;overflow:hidden;}
.bar__fill{height:100%;background:var(--accent);}

/* Badges */
.badge{display:inline-flex;align-items:center;gap:6pt;padding:2pt 8pt;border-radius:9999px;font-size:9pt;font-weight:600;border:1px solid var(--border);}
.badge--warn{color:var(--warn);background:var(--warn-bg);border-color:rgba(180,83,9,.4);}
.badge--risk{color:var(--risk);background:var(--risk-bg);border-color:rgba(185,28,28,.3);}
.badge--neutral{color:var(--ink);background:#F3F4F6;border-color:var(--border);}
.status-list{display:flex;flex-direction:column;gap:4pt;align-items:flex-start;}
.status-list .badge{white-space:normal;justify-content:flex-start;text-align:left;}

.footer{display:flex;justify-content:space-between;align-items:center;font-size:8.6pt;color:var(--ink-muted);padding:6pt 8pt 0;border-top:1px solid rgba(17,24,39,.12);}

.legend{font-size:8.8pt;color:var(--ink-muted);margin-top:6pt;}
</style>
</head>
<body>
<?php
/** @var array|null $metrics */
$formatNumber = static function (int $value): string {
    return number_format($value, 0, ',', '.');
};

$escapeNoBreak = static function (?string $value): string {
    $escaped = html_escape($value ?? '');
    return str_replace(' ', '&nbsp;', $escaped);
};

$thresholdRatio = isset($threshold_ratio) ? (float) $threshold_ratio : 0.9;
$thresholdPercent = $threshold_percent ?? number_format($thresholdRatio * 100, 0, ',', '.') . ' %';
$metrics = $metrics ?? [];
$formatSlotsSummary = static function (array $metric) use ($formatNumber): string {
    $template = lang('dashboard_slots_summary') ?: 'Slots: %planned% / %required%';
    $fallback = lang('dashboard_slots_summary_fallback') ?: 'Slots: —';
    $placeholder = '—';

    $plannedRaw = $metric['slots_planned_raw'] ?? null;
    $requiredRaw = $metric['slots_required_raw'] ?? null;

    $planned = $placeholder;
    if ($plannedRaw !== null) {
        $plannedValue = max(0, (int) $plannedRaw);
        $planned = $formatNumber($plannedValue);
    }

    $required = $placeholder;
    if ($requiredRaw !== null) {
        $requiredValue = max(0, (int) $requiredRaw);

        if ($requiredValue > 0) {
            $required = $formatNumber($requiredValue);
        }
    }

    if ($planned === $placeholder && $required === $placeholder) {
        return $fallback;
    }

    return str_replace(['%planned%', '%required%'], [$planned, $required], $template);
};

$formatAfter15Percent = static function (array $metric): string {
    if (empty($metric['after_15_evaluable']) || !array_key_exists('after_15_percent', $metric)) {
        return '—';
    }

    $after15Percent = $metric['after_15_percent'];

    if ($after15Percent === null) {
        return '—';
    }

    return number_format((float) $after15Percent, 1, ',', '.') . ' %';
};

$hasStatusReason = static function (array $metric, string $statusReason): bool {
    $statusReasons = $metric['status_reasons'] ?? null;

    if (!is_array($statusReasons)) {
        return false;
    }

    return in_array($statusReason, $statusReasons, true);
};

$resolveStatusBadges = static function (array $metric): array {
    $statusReasons =
        isset($metric['status_reasons']) && is_array($metric['status_reasons'])
            ? array_values(
                array_filter(
                    $metric['status_reasons'],
                    static fn($reason): bool => is_string($reason) && $reason !== '',
                ),
            )
            : [];

    $badges = [];

    foreach ($statusReasons as $statusReason) {
        if ($statusReason === 'booking_goal_missed') {
            $badges[] = [
                'class' => 'badge--risk',
                'label' => lang('dashboard_booking_goal_missed') ?: 'Buchungsziel verfehlt',
            ];
            continue;
        }

        if ($statusReason === 'after_15_goal_missed') {
            $badges[] = [
                'class' => 'badge--warn',
                'label' => lang('dashboard_after_15_goal_missed') ?: '15-Uhr-Vorgabe verfehlt',
            ];
            continue;
        }

        if ($statusReason === 'capacity_gap') {
            $badges[] = [
                'class' => 'badge--warn',
                'label' => lang('dashboard_slots_gap_badge') ?: 'Kapazitätslücke',
            ];
        }
    }

    if (!empty($badges)) {
        return $badges;
    }

    if (empty($metric['has_plan'])) {
        return [
            [
                'class' => 'badge--neutral',
                'label' => lang('no_plan_in_period') ?: 'Kein Arbeitsplan im Zeitraum',
            ],
        ];
    }

    if (empty($metric['has_explicit_target']) || !empty($metric['is_zero_target'])) {
        return [
            [
                'class' => 'badge--neutral',
                'label' => lang('dashboard_no_target') ?: 'Kein Ziel',
            ],
        ];
    }

    return [];
};

$normalizeMetric = static function (array $metric) use ($thresholdRatio, $formatNumber): array {
    $target = (int) ($metric['target_raw'] ?? ($metric['target'] ?? 0));
    $booked = (int) ($metric['booked_raw'] ?? ($metric['booked'] ?? 0));
    $open = (int) ($metric['open_raw'] ?? ($metric['open'] ?? 0));
    $fill = isset($metric['fill_ratio']) ? (float) $metric['fill_ratio'] : ($target > 0 ? $booked / $target : 0.0);

    $gapToThreshold = (int) ($metric['gap_to_threshold'] ?? max((int) ceil($thresholdRatio * $target) - $booked, 0));

    $isUnderTarget = $target > 0 && $gapToThreshold > 0;
    $isZeroTarget = !empty($metric['is_zero_target']) || $target === 0;

    $statusLabel = (string) ($metric['status_label'] ?? '');

    if ($statusLabel === '') {
        $statusLabel = $isUnderTarget ? 'Unter Ziel' : 'Ok';

        if ($isZeroTarget) {
            $statusLabel = 'Kein Ziel gepflegt';
        }
    }

    return array_merge($metric, [
        'target_raw' => $target,
        'booked_raw' => $booked,
        'open_raw' => $open,
        'gap_to_threshold' => $gapToThreshold,
        'gap_to_threshold_formatted' => $metric['gap_to_threshold_formatted'] ?? $formatNumber(max($gapToThreshold, 0)),
        'fill_ratio' => $fill,
        'fill_rate_percent_value' => isset($metric['fill_rate_percent_value'])
            ? (int) $metric['fill_rate_percent_value']
            : (int) round($fill * 100),
        'status_variant' => $metric['status_variant'] ?? ($isUnderTarget ? 'warn' : ($isZeroTarget ? 'muted' : 'ok')),
        'status_label' => $statusLabel,
        'needs_attention' => $gapToThreshold > 0,
        'has_capacity_gap' => !empty($metric['has_capacity_gap']),
        'is_zero_target' => $isZeroTarget,
    ]);
};

$principalPages = is_array($principal_pages ?? null) ? $principal_pages : [];
$preparedMetrics = [];
$preparedPrincipalPages = [];

if (!empty($principalPages)) {
    foreach ($principalPages as $pageMetrics) {
        if (!is_array($pageMetrics)) {
            continue;
        }

        $normalizedPageMetrics = [];

        foreach ($pageMetrics as $pageMetric) {
            if (!is_array($pageMetric)) {
                continue;
            }

            $normalizedMetric = $normalizeMetric($pageMetric);
            $normalizedPageMetrics[] = $normalizedMetric;
            $preparedMetrics[] = $normalizedMetric;
        }

        $preparedPrincipalPages[] = $normalizedPageMetrics;
    }
}

if (empty($preparedPrincipalPages)) {
    $metricRows = array_values(array_filter($metrics, static fn($metric): bool => is_array($metric)));
    $preparedMetrics = array_map($normalizeMetric, $metricRows);
    $firstPageSize = 5;
    $continuationPageSize = 13;
    $remainingMetrics = $preparedMetrics;

    if (empty($remainingMetrics)) {
        $preparedPrincipalPages = [[]];
    } else {
        $preparedPrincipalPages[] = array_splice($remainingMetrics, 0, $firstPageSize);

        while (!empty($remainingMetrics)) {
            $preparedPrincipalPages[] = array_splice($remainingMetrics, 0, $continuationPageSize);
        }
    }
}

$totalPages = max(1, count($preparedPrincipalPages));

$principalOverview = is_array($principal_overview ?? null) ? $principal_overview : [];

$teachersTotal = isset($principalOverview['teachers_total'])
    ? max(0, (int) $principalOverview['teachers_total'])
    : count($preparedMetrics);
$belowCount = isset($principalOverview['below_count'])
    ? max(0, (int) $principalOverview['below_count'])
    : count(
        array_filter($preparedMetrics, static fn(array $metric): bool => (int) ($metric['gap_to_threshold'] ?? 0) > 0),
    );
$inTargetCount = isset($principalOverview['in_target_count'])
    ? max(0, (int) $principalOverview['in_target_count'])
    : max($teachersTotal - $belowCount, 0);
$gapTotal = isset($principalOverview['gap_total'])
    ? max(0, (int) $principalOverview['gap_total'])
    : array_sum(array_map(static fn(array $metric): int => (int) ($metric['gap_to_threshold'] ?? 0), $preparedMetrics));

$bookedDistinctFormatted =
    (string) ($principalOverview['booked_distinct_formatted'] ??
        ($summary['booked_distinct_total_formatted'] ?? ($summary['booked_total_formatted'] ?? '0')));
$targetTotalFormatted =
    (string) ($principalOverview['target_total_formatted'] ?? ($summary['target_total_formatted'] ?? '0'));
$fillRateValue = isset($principalOverview['fill_rate_value'])
    ? (float) $principalOverview['fill_rate_value']
    : (float) ($summary['fill_rate'] ?? 0.0);
$generatedAt = $generated_at_text ?? date('d.m.Y, H:i');

$donutImageSize = 120;
$donutImageThickness = 20;
$primaryDonutImage = donut_image_data_url($fillRateValue, $donutImageSize, $donutImageThickness, [
    'background' => '#E5E7EB',
    'foreground' => '#16A34A',
]);

$progressInTarget = $teachersTotal > 0 ? max(0.0, min(1.0, $inTargetCount / $teachersTotal)) : 0.0;
$inTargetDonutImage = donut_image_data_url($progressInTarget, $donutImageSize, $donutImageThickness, [
    'background' => '#E5E7EB',
    'foreground' => '#16A34A',
]);

$gapTotalFormatted = (string) ($principalOverview['gap_total_formatted'] ?? $formatNumber(max($gapTotal, 0)));
$inTargetLabel =
    (string) ($principalOverview['in_target_label'] ??
        number_format($inTargetCount, 0, ',', '.') .
            ' / ' .
            number_format($teachersTotal, 0, ',', '.') .
            ' Lehrkräfte im Buchungsziel');

if (isset($principalOverview['top_attention']) && is_array($principalOverview['top_attention'])) {
    $topAttention = array_values(
        array_filter($principalOverview['top_attention'], static fn($metric): bool => is_array($metric)),
    );
} else {
    $needsAttentionMetrics = array_filter(
        $preparedMetrics,
        static fn(array $metric): bool => !empty($metric['status_reasons']),
    );
    $topAttention = array_slice($needsAttentionMetrics, 0, 5);
}

$capacityGapLabel =
    (string) ($principalOverview['capacity_gap_label'] ?? (lang('dashboard_slots_gap_badge') ?: 'Kapazitätslücke'));
$bookingGoalMissedCount = isset($principalOverview['booking_goal_missed_count'])
    ? max(0, (int) $principalOverview['booking_goal_missed_count'])
    : $belowCount;
$after15GoalMissedCount = isset($principalOverview['after_15_goal_missed_count'])
    ? max(0, (int) $principalOverview['after_15_goal_missed_count'])
    : count(
        array_filter(
            $preparedMetrics,
            static fn(array $metric): bool => $hasStatusReason($metric, 'after_15_goal_missed'),
        ),
    );
$capacityGapCount = isset($principalOverview['capacity_gap_count'])
    ? max(0, (int) $principalOverview['capacity_gap_count'])
    : count(array_filter($preparedMetrics, static fn(array $metric): bool => !empty($metric['has_capacity_gap'])));
$attentionCount = isset($principalOverview['attention_count'])
    ? max(0, (int) $principalOverview['attention_count'])
    : count(array_filter($preparedMetrics, static fn(array $metric): bool => !empty($metric['status_reasons'])));
?>
<?php foreach ($preparedPrincipalPages as $pageIndex => $pageMetrics):

    $pageNumber = $pageIndex + 1;
    $isFirstPage = $pageIndex === 0;
    ?>
<div class="page<?= $pageNumber > 1 ? ' page--break' : '' ?>">
  <div class="page__content">
    <?php if ($isFirstPage): ?>
      <header class="header" role="banner">
        <div class="header__titles">
          <h1 class="header__title">Schulleitungsreport</h1>
          <p class="header__meta">
            Übersicht zu den Klassenleitungssprechtagen (<?= html_escape($period_label ?? 'Zeitraum offen') ?>)
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
                <?php if ($primaryDonutImage): ?>
                  <div class="donut__figure">
                    <img src="<?= html_escape($primaryDonutImage) ?>" alt="" class="donut__image" />
                    <span class="donut__label"><?= html_escape($summary['fill_rate_formatted'] ?? '0 %') ?></span>
                  </div>
                <?php else: ?>
                  <div class="donut__figure donut__figure--fallback">
                    <span class="donut__label"><?= html_escape($summary['fill_rate_formatted'] ?? '0 %') ?></span>
                  </div>
                <?php endif; ?>
                <div class="donut__stats">
                  <p class="donut__caption"><?= html_escape($bookedDistinctFormatted) ?> von <?= html_escape(
     $targetTotalFormatted,
 ) ?> Eltern erreicht</p>
                </div>
              </div>
            </article>

            <article class="card" aria-label="Lehrkräfte im Ziel">
              <h3><?= html_escape(lang('dashboard_principal_in_booking_goal') ?: 'Lehrkräfte im Buchungsziel') ?></h3>
              <div class="donut">
                <?php if ($inTargetDonutImage): ?>
                  <div class="donut__figure">
                    <img src="<?= html_escape($inTargetDonutImage) ?>" alt="" class="donut__image" />
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
                  <p class="donut__caption"><?= html_escape($inTargetLabel) ?></p>
                </div>
              </div>
            </article>

            <article class="card" aria-label="Fehlende Eltern bis Schwelle">
              <h3><?= html_escape(
                  lang('dashboard_principal_missing_until_booking_goal') ?: 'Fehlend bis Buchungsziel',
              ) ?></h3>
              <div class="kpi">
                <span>Fehlende Eltern:</span>
                <strong><?= html_escape($gapTotalFormatted) ?></strong>
              </div>
            </article>
          </div>
        </div>

        <aside class="callout">
          <h3>Handlungsbedarf</h3>
          <?php if ($teachersTotal === 0): ?>
            <p>Keine Daten für die ausgewählten Filter vorhanden.</p>
          <?php else: ?>
            <p><?= html_escape(number_format($attentionCount, 0, ',', '.')) ?> von <?= html_escape(
     number_format($teachersTotal, 0, ',', '.'),
 ) ?> Lehrkräften brauchen aktuell Nachsteuerung.</p>
            <ul>
              <li><?= html_escape(number_format($bookingGoalMissedCount, 0, ',', '.')) ?> Lehrkräfte: <?= html_escape(
     lang('dashboard_booking_goal_missed') ?: 'Buchungsziel verfehlt',
 ) ?></li>
              <li><?= html_escape(number_format($after15GoalMissedCount, 0, ',', '.')) ?> Lehrkräfte: <?= html_escape(
     lang('dashboard_after_15_goal_missed') ?: '15-Uhr-Vorgabe verfehlt',
 ) ?></li>
              <li><?= html_escape(number_format($capacityGapCount, 0, ',', '.')) ?> Lehrkräfte: <?= html_escape(
     $capacityGapLabel,
 ) ?></li>
            </ul>
          <?php endif; ?>
          <?php if ($topAttention): ?>
            <div class="callout__section-title">Priorisierte Fälle</div>
            <ul>
              <?php foreach ($topAttention as $metric): ?>
                <li>
                  <?= html_escape($metric['provider_name'] ?? '') ?>
                  <?php if ($hasStatusReason($metric, 'booking_goal_missed')): ?>
                    – <strong><?= html_escape($metric['gap_to_threshold_formatted'] ?? '0') ?></strong> bis Buchungsziel
                  <?php endif; ?>
                  <?php if ($hasStatusReason($metric, 'after_15_goal_missed')): ?>
                    <?php if ($hasStatusReason($metric, 'booking_goal_missed')): ?>,<?php else: ?>–<?php endif; ?>
                    <?= html_escape($formatAfter15Percent($metric)) ?> nach 15:00
                  <?php endif; ?>
                  <?php if ($hasStatusReason($metric, 'capacity_gap')): ?>
                    <?php if (
                        $hasStatusReason($metric, 'booking_goal_missed') ||
                        $hasStatusReason($metric, 'after_15_goal_missed')
                    ): ?>,<?php else: ?>–<?php endif; ?>
                    <?= html_escape($capacityGapLabel) ?>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php elseif ($teachersTotal > 0 && $inTargetCount === $teachersTotal): ?>
            <p>Alle Klassenleitungen liegen aktuell im Buchungsziel.</p>
          <?php endif; ?>
        </aside>
      </section>
    <?php endif; ?>

    <section class="card table-card" aria-label="Übersicht aller Lehrkräfte">
      <?php if (!empty($pageMetrics)): ?>
        <table>
          <thead>
            <tr>
              <th>Lehrkraft</th>
              <th class="col-right col-size">Klassengröße</th>
              <th class="col-right col-booked">Gebucht</th>
              <th class="col-fill">Auslastung</th>
              <th class="col-right col-after-15"><?= html_escape(
                  lang('dashboard_principal_after_15_heading') ?: 'Nach 15:00',
              ) ?></th>
              <th class="col-right col-gap"><?= html_escape(
                  lang('dashboard_principal_until_booking_goal') ?: 'bis Buchungsziel',
              ) ?></th>
              <th class="col-status">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pageMetrics as $metric):

                $fillPercent = (int) max(0, min(100, (int) ($metric['fill_rate_percent_value'] ?? 0)));
                $slotSummary = $formatSlotsSummary($metric);
                $statusBadges = $resolveStatusBadges($metric);
                ?>
            <tr>
              <td>
                <p class="provider"><?= html_escape($metric['provider_name'] ?? '') ?></p>
                <div class="provider__meta">
                  <span class="provider__slots"><?= html_escape($slotSummary) ?></span>
                </div>
              </td>
              <td class="col-right col-size">
                <?= !empty($metric['is_zero_target'])
                    ? '&mdash;'
                    : html_escape($metric['target'] ?? ($metric['target_raw'] ?? '0')) ?>
              </td>
              <td class="col-right col-booked"><?= html_escape(
                  $metric['booked'] ?? ($metric['booked_raw'] ?? '0'),
              ) ?></td>
              <td class="col-fill">
                <span><?= html_escape($metric['fill_rate_percent'] ?? $fillPercent . ' %') ?></span>
                <div class="bar"><div class="bar__fill" style="width: <?= $fillPercent ?>%;"></div></div>
              </td>
              <td class="col-right nowrap col-after-15"><?= $escapeNoBreak($formatAfter15Percent($metric)) ?></td>
              <td class="col-right nowrap col-gap">
                <?= (int) ($metric['gap_to_threshold'] ?? 0) > 0
                    ? $escapeNoBreak(
                        $metric['gap_to_threshold_formatted'] ?? (string) (int) ($metric['gap_to_threshold'] ?? 0),
                    )
                    : '&mdash;' ?>
              </td>
              <td class="col-status">
                <?php if (!empty($statusBadges)): ?>
                  <div class="status-list">
                    <?php foreach ($statusBadges as $statusBadge): ?>
                      <span class="badge <?= html_escape($statusBadge['class']) ?>"><?= html_escape(
    $statusBadge['label'],
) ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  &mdash;
                <?php endif; ?>
              </td>
            </tr>
            <?php
            endforeach; ?>
          </tbody>
        </table>
      <?php elseif ($isFirstPage): ?>
        <p class="legend">Keine Daten für die ausgewählten Filter vorhanden.</p>
      <?php endif; ?>
    </section>
  </div>

  <footer class="footer" role="contentinfo">
    <span class="footer__timestamp">Stand: <?= html_escape($generatedAt) ?></span>
    <span class="footer__page" aria-label="Seitenzahl"><?= $pageNumber ?>/<?= $totalPages ?></span>
  </footer>
</div>
<?php
endforeach; ?>
<script>window.chartsReady = true;</script>
</body>
</html>
