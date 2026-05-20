<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8" />
<title>Terminübersicht für Eltern</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<style>
:root{
  --accent:#16A34A;
  --ink:#111827;
  --ink-muted:#6B7280;
  --border:#E5E7EB;
  --surface:#FFFFFF;
  --radius:12pt;
}
@page{size:A4;margin:12mm;}
html{-webkit-print-color-adjust:exact;print-color-adjust:exact;font-size:10pt;}
body{margin:0;font-family:"Inter",system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif;color:var(--ink);background:#fff;line-height:1.45;}
.page{height:calc(297mm - 24mm);display:grid;grid-template-rows:auto 1fr auto;padding:12pt 10pt 12pt;gap:14pt;box-sizing:border-box;page-break-inside:avoid;break-inside:avoid;}
.page--break{page-break-before:always;}
.header{display:flex;gap:12pt;align-items:flex-start;}
.header__titles{display:flex;flex-direction:column;gap:4pt;max-width:72%;}
.header__title{margin:0;font-size:20pt;font-weight:600;}
.header__meta{margin:0;font-size:9.6pt;color:var(--ink-muted);}
.header__logo{margin-left:auto;display:block;}
.logo{width:120px;max-height:64px;object-fit:contain;}

.content{display:flex;flex-direction:column;gap:12pt;padding-bottom:18pt;box-sizing:border-box;}
.teacher__heading{display:flex;flex-direction:column;gap:4pt;}
.teacher__name{margin:0;font-size:16pt;font-weight:600;}
.teacher__note{margin:0;font-size:9.2pt;color:var(--ink-muted);}

.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:12pt;display:flex;flex-direction:column;gap:10pt;}
table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;font-size:9.6pt;margin-bottom:4pt;}
thead{display:table-header-group;}
thead th{text-align:left;font-size:8.8pt;color:var(--ink-muted);font-weight:600;padding:8pt 10pt;border-bottom:1px solid var(--border);}
tbody{display:table-row-group;}
tbody tr{page-break-inside:avoid;break-inside:avoid;}
tbody td{padding:7pt 10pt;border-bottom:1px solid var(--border);vertical-align:middle;}
tbody tr:last-child td{border-bottom:none;}
.col-parent{width:46%;}
.col-date{width:28%;}
.col-start,.col-end{width:13%;}
.empty{font-size:9.4pt;color:var(--ink-muted);margin:0;}
.footer{display:flex;justify-content:space-between;align-items:center;font-size:8.6pt;color:var(--ink-muted);padding-top:6pt;border-top:1px solid rgba(17,24,39,.12);}
</style>
</head>
<body>
<?php
/** @var string|null $school_name */
/** @var string|null $logo_data_url */
/** @var string|null $generated_at_text */
/** @var string|null $period_label */
/** @var string|null $provider_name */
/** @var array|null $appointment_pages */
$schoolName = $school_name ?: 'Forscherhaus Grundschule';
$generatedAt = $generated_at_text ?? date('d.m.Y, H:i');
$periodLabel = $period_label ?? '';
$providerName = $provider_name ?? '';
$appointmentPages = $appointment_pages ?? [
    [
        'chunk_index' => 0,
        'chunks_total' => 1,
        'appointments' => [],
        'has_any_appointments' => false,
    ],
];
$timeSuffixRaw = lang('pdf_export_time_suffix');
$timeSuffixLabel = is_string($timeSuffixRaw) ? trim($timeSuffixRaw) : '';
$timeFormatSetting = setting('time_format') ?: 'military';
$appendTimeSuffix = $timeSuffixLabel !== '' && $timeFormatSetting === 'military';
?>
<?php foreach ($appointmentPages as $pageIndex => $pageData):

    $pageNumber = $pageIndex + 1;
    $chunkIndex = (int) ($pageData['chunk_index'] ?? 0);
    $chunksTotal = max(1, (int) ($pageData['chunks_total'] ?? 1));
    $appointments = $pageData['appointments'] ?? [];
    $hasAnyAppointments = (bool) ($pageData['has_any_appointments'] ?? false);
    $isContinuation = $chunkIndex > 0;
    ?>
  <div class="page<?= $pageNumber > 1 ? ' page--break' : '' ?>">
    <header class="header">
      <div class="header__titles">
        <h1 class="header__title">Terminübersicht für Eltern</h1>
        <?php if ($periodLabel): ?>
          <p class="header__meta">Klassenleitungsgespräche · <?= html_escape($periodLabel) ?></p>
        <?php endif; ?>
      </div>
      <?php if (!empty($logo_data_url)): ?>
        <img src="<?= html_escape($logo_data_url) ?>" alt="<?= html_escape($schoolName) ?>" class="logo header__logo" />
      <?php endif; ?>
    </header>

    <main class="content">
      <div class="teacher__heading">
        <h2 class="teacher__name"><?= html_escape($providerName) ?></h2>
        <?php if ($isContinuation): ?>
          <p class="teacher__note">Fortsetzung</p>
        <?php endif; ?>
      </div>

      <section class="card">
        <?php if (!empty($appointments)): ?>
          <table>
            <thead>
              <tr>
                <th class="col-parent">Eingetragener Name</th>
                <th class="col-date">Datum</th>
                <th class="col-start">Beginn</th>
                <th class="col-end">Ende</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($appointments as $appointment):

                  $startDisplay = (string) ($appointment['start'] ?? '');
                  $endDisplay = (string) ($appointment['end'] ?? '');
                  if ($appendTimeSuffix && $startDisplay !== '') {
                      $startDisplay = rtrim($startDisplay) . ' ' . $timeSuffixLabel;
                  }
                  if ($appendTimeSuffix && $endDisplay !== '') {
                      $endDisplay = rtrim($endDisplay) . ' ' . $timeSuffixLabel;
                  }
                  ?>
                <tr>
                  <td><?= html_escape((string) ($appointment['parent_name'] ?? '—')) ?></td>
                  <td><?= html_escape((string) ($appointment['date'] ?? '')) ?></td>
                  <td><?= html_escape($startDisplay) ?></td>
                  <td><?= html_escape($endDisplay) ?></td>
                </tr>
              <?php
              endforeach; ?>
            </tbody>
          </table>
        <?php elseif (!$hasAnyAppointments): ?>
          <p class="empty">Keine Termine im Zeitraum.</p>
        <?php endif; ?>
      </section>
    </main>

    <footer class="footer">
      <span>Stand: <?= html_escape($generatedAt) ?></span>
      <span><?= $chunkIndex + 1 ?>/<?= $chunksTotal ?></span>
    </footer>
  </div>
<?php
endforeach; ?>
<script>window.chartsReady = true;</script>
</body>
</html>
