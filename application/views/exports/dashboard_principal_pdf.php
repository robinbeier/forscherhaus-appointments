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
html{-webkit-print-color-adjust:exact;print-color-adjust:exact;font-size:10pt;height:100%;}
body{margin:0;color:var(--ink);font-family:"Inter",system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif;background:#fff;line-height:1.45;min-height:100%;display:flex;}

/* ===== Layout ===== */
.page{flex:1;min-height:100%;display:flex;flex-direction:column;gap:14pt;padding:0 8pt;}
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

/* Pill */
.pill{display:inline-flex;align-items:center;gap:4pt;padding:1pt 8pt;border-radius:999px;font-size:8.8pt;font-weight:600;border:1px solid transparent;}
.pill--ok{color:var(--accent);background:var(--accent-weak);border-color:rgba(22,163,74,.35);}
.pill--warn{color:var(--warn);background:var(--warn-bg);border-color:rgba(180,83,9,.4);}
.pill--risk{color:var(--risk);background:var(--risk-bg);border-color:rgba(185,28,28,.3);}

/* Tabelle */
.table-card{padding:12pt 0 0 0;}
table{width:100%;border-collapse:separate;border-spacing:0;font-size:9.8pt;margin-top:0;}
thead th{text-align:left;font-weight:600;font-size:9.6pt;color:var(--ink-muted);padding:10pt 12pt;border-bottom:1px solid var(--border);}
tbody td{padding:10pt 12pt;border-bottom:1px solid var(--border);vertical-align:middle;}
tbody tr:last-child td{border-bottom:none;}
.col-right{text-align:right;}
.col-size{width:10%;}
.col-booked{width:12%;}
.col-fill{width:16%;}
.col-gap{width:12%;white-space:nowrap;}
.col-status{width:19%; text-align:center;}
.nowrap{white-space:nowrap;}
.provider{margin:0;font-weight:600;}
.provider__meta{margin:2pt 0 0 0;color:var(--ink-muted);font-size:8.8pt;}

/* Balken */
.bar{margin-top:6pt;height:8px;background:#F3F4F6;border-radius:999px;overflow:hidden;}
.bar__fill{height:100%;background:var(--accent);}

/* Badges */
.badge{display:inline-flex;align-items:center;gap:6pt;padding:2pt 8pt;border-radius:9999px;font-size:9pt;font-weight:600;border:1px solid var(--border);}
.badge--warn{color:var(--warn);background:var(--warn-bg);border-color:rgba(180,83,9,.4);}
.badge--neutral{color:var(--ink);background:#F3F4F6;border-color:var(--border);}

.footer{margin-top:auto;display:flex;justify-content:space-between;align-items:center;font-size:8.6pt;color:var(--ink-muted);padding:6pt 8pt 0;border-top:1px solid rgba(17,24,39,.12);}

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

$thresholdRatio = isset($threshold_ratio) ? (float) $threshold_ratio : 0.75;
$thresholdPercent = $threshold_percent ?? number_format($thresholdRatio * 100, 0, ',', '.') . ' %';
$metrics = $metrics ?? [];
$preparedMetrics = [];

foreach ($metrics as $metric) {
    $target = (int) ($metric['target_raw'] ?? ($metric['target'] ?? 0));
    $booked = (int) ($metric['booked_raw'] ?? ($metric['booked'] ?? 0));
    $open = (int) ($metric['open_raw'] ?? ($metric['open'] ?? 0));
    $fill = $target > 0 ? $booked / $target : 0.0;

    $gapToThreshold = (int) ($metric['gap_to_threshold'] ?? max((int) ceil($thresholdRatio * $target) - $booked, 0));

    $isUnderTarget = $target > 0 && $gapToThreshold > 0;
    $statusVariant = $isUnderTarget ? 'warn' : 'ok';
    $statusLabel = $isUnderTarget ? 'Unter Ziel' : 'Ok';
    if (!empty($metric['is_zero_target']) || $target === 0) {
        $statusVariant = 'muted';
        $statusLabel = 'Kein Ziel gepflegt';
    }

    $preparedMetrics[] = array_merge($metric, [
        'target_raw' => $target,
        'booked_raw' => $booked,
        'open_raw' => $open,
        'gap_to_threshold' => $gapToThreshold,
        'gap_to_threshold_formatted' => $formatNumber(max($gapToThreshold, 0)),
        'fill_ratio' => $fill,
        'fill_rate_percent_value' => isset($metric['fill_rate_percent_value'])
            ? (int) $metric['fill_rate_percent_value']
            : (int) round($fill * 100),
        'status_variant' => $statusVariant,
        'status_label' => $statusLabel,
        'needs_attention' => $gapToThreshold > 0,
    ]);
}

usort($preparedMetrics, static function (array $left, array $right): int {
    $gapSort = ($right['gap_to_threshold'] ?? 0) <=> ($left['gap_to_threshold'] ?? 0);
    if ($gapSort !== 0) {
        return $gapSort;
    }

    return ($left['fill_ratio'] ?? 0) <=> ($right['fill_ratio'] ?? 0);
});

$teachersTotal = count($preparedMetrics);
$belowCount = count(
    array_filter($preparedMetrics, static fn(array $metric): bool => (int) ($metric['gap_to_threshold'] ?? 0) > 0),
);
$inTargetCount = $teachersTotal - $belowCount;
$gapTotal = array_sum(
    array_map(static fn(array $metric): int => (int) ($metric['gap_to_threshold'] ?? 0), $preparedMetrics),
);

$bookedDistinctFormatted = $summary['booked_distinct_total_formatted'] ?? ($summary['booked_total_formatted'] ?? '0');
$targetTotalFormatted = $summary['target_total_formatted'] ?? '0';
$fillRateValue = (float) ($summary['fill_rate'] ?? 0.0);
$generatedAt = date('d.m.Y, H:i');

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

$gapTotalFormatted = $formatNumber(max($gapTotal, 0));
$inTargetLabel =
    number_format($inTargetCount, 0, ',', '.') .
    ' / ' .
    number_format($teachersTotal, 0, ',', '.') .
    ' Lehrkräfte über Ziel';

$needsAttentionMetrics = array_filter(
    $preparedMetrics,
    static fn(array $metric): bool => (int) ($metric['gap_to_threshold'] ?? 0) > 0,
);
$topAttention = array_slice($needsAttentionMetrics, 0, 5);
?>
<div class="page">
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
          <h3>Lehrkräfte ≥ <?= html_escape($thresholdPercent) ?></h3>
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

        <article class="card" aria-label="Fehlende Kunder bis Schwelle">
          <h3>Fehlend bis <?= html_escape($thresholdPercent) ?></h3>
          <div class="kpi">
            <span>Fehlende Kinder</span>
            <strong><?= html_escape($gapTotalFormatted) ?></strong>
          </div>
        </article>
      </div>
    </div>

    <aside class="callout">
      <h3>Handlungsbedarf</h3>
      <p><?= html_escape(number_format($belowCount, 0, ',', '.')) ?> von <?= html_escape(
     number_format($teachersTotal, 0, ',', '.'),
 ) ?> Lehrkräften liegen unter <?= html_escape($thresholdPercent) ?>.</p>
      <?php if ($topAttention): ?>
        <ul>
          <?php foreach ($topAttention as $metric): ?>
            <li>
              <?= html_escape($metric['provider_name'] ?? '') ?>
              – <strong><?= html_escape($metric['gap_to_threshold_formatted'] ?? '0') ?></strong> offen
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p>Alle Klassenleitungen haben mit mehr als <?= html_escape(
            $thresholdPercent,
        ) ?> ihrer Eltern ein Termin gemacht.</p>
      <?php endif; ?>
    </aside>
  </section>

  <section class="card table-card" aria-label="Übersicht aller Lehrkräfte">
    <?php if ($preparedMetrics): ?>
      <table>
        <thead>
          <tr>
            <th>Lehrkraft</th>
            <th class="col-right col-size">Klassengröße</th>
            <th class="col-right col-booked">Gebucht</th>
            <th class="col-fill">Auslastung</th>
            <th class="col-right col-gap">bis <?= $escapeNoBreak($thresholdPercent) ?></th>
            <th class="col-status">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($preparedMetrics as $metric):

              $fillPercent = (int) max(0, min(100, (int) ($metric['fill_rate_percent_value'] ?? 0)));
              $isUnderThreshold = (int) ($metric['gap_to_threshold'] ?? 0) > 0;
              $badgeClass = $isUnderThreshold ? 'badge--warn' : 'badge--neutral';
              ?>
          <tr>
            <td>
              <p class="provider"><?= html_escape($metric['provider_name'] ?? '') ?></p>
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
            <td class="col-right nowrap col-gap">
              <?= (int) ($metric['gap_to_threshold'] ?? 0) > 0
                  ? $escapeNoBreak(
                      $metric['gap_to_threshold_formatted'] ?? (string) (int) ($metric['gap_to_threshold'] ?? 0),
                  )
                  : '&mdash;' ?>
            </td>
            <td class="col-status"><span class="badge <?= $badgeClass ?>"><?= html_escape(
    $metric['status_label'] ?? '',
) ?></span></td>
          </tr>
          <?php
          endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="legend">Keine Daten für die ausgewählten Filter vorhanden.</p>
    <?php endif; ?>
  </section>
  <footer class="footer" role="contentinfo">
    <span class="footer__timestamp">Stand: <?= html_escape($generatedAt) ?></span>
    <span class="footer__page" aria-label="Seitenzahl">1/1</span>
  </footer>
</div>
<script>window.chartsReady = true;</script>
</body>
</html>
