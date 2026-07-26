<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8" />
<title>Vorbereitung Klassenleitungsgespräche</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<style>
:root{
  --ink:#111827;
  --ink-muted:#6B7280;
  --border:#D1D5DB;
  --surface:#FFFFFF;
  --radius:10pt;
}
@page{size:A4 landscape;margin:12mm;}
html{-webkit-print-color-adjust:exact;print-color-adjust:exact;font-size:10pt;}
body{margin:0;font-family:"Inter",system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif;color:var(--ink);background:#fff;line-height:1.4;}
.page{height:calc(210mm - 24mm);display:grid;grid-template-rows:auto 1fr auto;padding:8pt 10pt 10pt;gap:10pt;box-sizing:border-box;page-break-inside:avoid;break-inside:avoid;}
.page--break{page-break-before:always;}
.header{display:flex;gap:12pt;align-items:flex-start;}
.header__titles{display:flex;flex-direction:column;gap:3pt;max-width:76%;}
.header__title{margin:0;font-size:19pt;font-weight:600;}
.header__meta{margin:0;font-size:9.4pt;color:var(--ink-muted);}
.header__logo{margin-left:auto;display:block;}
.logo{width:112px;max-height:54px;object-fit:contain;}

.content{display:flex;flex-direction:column;gap:8pt;min-height:0;}
.teacher__heading{display:flex;align-items:baseline;gap:8pt;}
.teacher__name{margin:0;font-size:14pt;font-weight:600;}
.teacher__note{margin:0;font-size:9pt;color:var(--ink-muted);}

.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:9pt;display:flex;flex-direction:column;min-height:0;}
table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;font-size:9.4pt;}
thead{display:table-header-group;}
thead th{text-align:left;font-size:8.8pt;color:var(--ink-muted);font-weight:600;padding:6pt 8pt;border-bottom:1px solid var(--border);}
tbody{display:table-row-group;}
tbody tr{page-break-inside:avoid;break-inside:avoid;}
tbody td{padding:6pt 8pt;border-bottom:1px solid var(--border);vertical-align:top;}
tbody tr:last-child td{border-bottom:none;}
.col-name{width:24%;}
.col-datetime{width:20%;}
.col-notes{width:56%;}
.appointment__name{font-weight:600;}
.appointment__datetime{white-space:nowrap;}
.notes__lines{height:54pt;display:grid;grid-template-rows:repeat(4,1fr);gap:2pt;}
.notes__line{border-bottom:1px solid rgba(107,114,128,.55);}
.empty{font-size:9.4pt;color:var(--ink-muted);margin:0;}
.footer{display:flex;justify-content:space-between;align-items:center;font-size:8.4pt;color:var(--ink-muted);padding-top:5pt;border-top:1px solid rgba(17,24,39,.12);}
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
        <h1 class="header__title">Vorbereitung Klassenleitungsgespräche</h1>
        <?php if ($periodLabel): ?>
          <p class="header__meta"><?= html_escape($periodLabel) ?></p>
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
                <th class="col-name">Name</th>
                <th class="col-datetime">Datum &amp; Uhrzeit</th>
                <th class="col-notes">Notizen</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($appointments as $appointment):

                  $startDisplay = (string) ($appointment['start'] ?? '');
                  $endDisplay = (string) ($appointment['end'] ?? '');
                  if ($appendTimeSuffix && $endDisplay !== '') {
                      $endDisplay = rtrim($endDisplay) . ' ' . $timeSuffixLabel;
                  }
                  $timeDisplay = trim(
                      $startDisplay . ($startDisplay !== '' && $endDisplay !== '' ? ' - ' : '') . $endDisplay,
                  );
                  ?>
                <tr>
                  <td class="appointment__name">
                    <?= html_escape((string) ($appointment['parent_name'] ?? '—')) ?>
                  </td>
                  <td class="appointment__datetime">
                    <div><?= html_escape((string) ($appointment['date'] ?? '')) ?></div>
                    <div><?= html_escape($timeDisplay) ?></div>
                  </td>
                  <td>
                    <div class="notes__lines" aria-hidden="true">
                      <div class="notes__line"></div>
                      <div class="notes__line"></div>
                      <div class="notes__line"></div>
                      <div class="notes__line"></div>
                    </div>
                  </td>
                </tr>
              <?php
              endforeach; ?>
            </tbody>
          </table>
        <?php elseif (!$hasAnyAppointments): ?>
          <p class="empty">Keine gebuchten Termine im Zeitraum.</p>
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
