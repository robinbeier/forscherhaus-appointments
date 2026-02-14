<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8" />
<title><?= html_escape(lang('dashboard_teacher_pdf_title') ?: 'Lehrkräfte-Report') ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<style>
:root{
  --accent:#16A34A;
  --accent-muted:#BBF7D0;
  --ink:#111827;
  --ink-muted:#6B7280;
  --ink-soft:#9CA3AF;
  --border:#E5E7EB;
  --surface:#FFFFFF;
  --muted-surface:#F9FAFB;
  --radius:12pt;
}
@page{size:A4;margin:12mm;}
html{-webkit-print-color-adjust:exact;print-color-adjust:exact;font-size:10pt;}
body{margin:0;font-family:"Inter",system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif;color:var(--ink);background:#fff;line-height:1.45;}
.page{height:calc(297mm - 24mm);display:grid;grid-template-rows:auto 1fr auto;padding:12pt 10pt 12pt;gap:12pt;box-sizing:border-box;page-break-inside:avoid;break-inside:avoid;}
.page--break{page-break-before:always;}
.header{display:flex;gap:12pt;align-items:flex-start;}
.header__titles{display:flex;flex-direction:column;gap:4pt;max-width:70%;}
.header__title{margin:0;font-size:20pt;font-weight:600;}
.header__meta{margin:0;font-size:9.6pt;color:var(--ink-muted);}
.header__chips{display:flex;flex-wrap:wrap;gap:6pt;}
.chip{display:inline-flex;align-items:center;gap:4pt;padding:3pt 10pt;border-radius:999px;background:var(--muted-surface);border:1px solid var(--border);font-size:8.8pt;font-weight:600;color:var(--ink-muted);}
.header__logo{margin-left:auto;display:block;}
.logo{width:120px;max-height:64px;object-fit:contain;}

.teacher{display:flex;flex-direction:column;gap:12pt;padding-bottom:18pt;box-sizing:border-box;}
.teacher__heading{display:flex;flex-direction:column;gap:4pt;}
.teacher__name{margin:0;font-size:16pt;font-weight:600;}
.teacher__note{margin:0;font-size:9.2pt;color:var(--ink-muted);}

.kpi-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:12pt;display:flex;flex-direction:column;gap:10pt;}
.kpi-card h3{margin:0;font-size:11pt;font-weight:600;color:var(--ink);}
.progress{display:flex;flex-direction:column;gap:6pt;}
.progress__bar{height:10px;border-radius:999px;overflow:hidden;background:#E5E7EB;display:flex;}
.progress__booked{background:var(--accent);}
.progress__open{background:#D1D5DB;}
.progress__labels{display:flex;gap:12pt;font-size:8.8pt;color:var(--ink-muted);flex-wrap:wrap;}
.progress__labels span{display:flex;align-items:center;gap:6pt;}
.legend__dot{width:10px;height:10px;border-radius:50%;display:inline-block;}
.legend__dot--booked{background:var(--accent);}
.legend__dot--open{background:#D1D5DB;}

.metrics-row{display:flex;gap:10pt;align-items:stretch;}
.metric{flex:1 1 0;min-width:0;border:1px solid var(--border);border-radius:10pt;padding:10pt;display:flex;flex-direction:column;gap:4pt;background:var(--surface);}
.metric span{font-size:8.8pt;color:var(--ink-muted);}
.metric strong{font-size:13pt;font-weight:600;color:var(--ink);}
.metric__value{display:flex;align-items:baseline;gap:4pt;}
.metric__suffix{font-size:9pt;color:var(--ink-soft);}

.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:12pt;display:flex;flex-direction:column;gap:10pt;}
.card--outreach{page-break-inside:avoid;break-inside:avoid;}
.card h3{margin:0;font-size:11pt;font-weight:600;}

table{width:100%;border-collapse:separate;border-spacing:0;font-size:9.4pt;margin-bottom:12pt;}
thead{display:table-header-group;}
thead th{text-align:left;font-size:8.8pt;color:var(--ink-muted);font-weight:600;padding:8pt 10pt;border-bottom:1px solid var(--border);}
tbody{display:table-row-group;}
tbody tr{page-break-inside:avoid;break-inside:avoid;}
tbody td{padding:9pt 10pt;border-bottom:1px solid var(--border);vertical-align:middle;}
tbody tr:last-child td{border-bottom:none;}
.col-date{text-align:left;width:30%;}
.col-start,.col-end{text-align:left;width:25%;}
.col-parent{width:20%;}
.nowrap{white-space:nowrap;}
.open-summary{font-size:9.2pt;color:var(--ink-muted);margin:0;}

.outreach__status{font-size:9.2pt;color:var(--ink-muted);margin:0;}
.outreach__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200pt,1fr));gap:10pt;}
.outreach__variant{border:1px solid var(--border);border-radius:10pt;padding:10pt;background:var(--muted-surface);display:flex;flex-direction:column;gap:6pt;}
.outreach__variant h4{margin:0;font-size:10pt;font-weight:600;color:var(--ink);}
.outreach__variant p{margin:0;font-size:9.2pt;color:var(--ink);}
.outreach__template{white-space:pre-line;}
.checklist{list-style:none;margin:4pt 0 0 0;padding:0;display:flex;flex-direction:column;gap:4pt;font-size:9.2pt;color:var(--ink-muted);}
.checklist li{display:flex;align-items:center;gap:6pt;}
.checklist-inline{margin:6pt 0 0 0;font-size:9.2pt;color:var(--ink-muted);}
.checklist-inline strong{color:var(--ink);display:block;margin-bottom:4pt;}
.checklist-inline-items{display:flex;align-items:center;gap:8pt;flex-wrap:wrap;}
.checklist-inline-items span{display:inline-flex;align-items:center;gap:4pt;}

.footer{display:flex;justify-content:space-between;align-items:center;font-size:8.6pt;color:var(--ink-muted);padding-top:6pt;border-top:1px solid rgba(17,24,39,.12);}
</style>
</head>
<body>
<?php
/** @var string|null $school_name */
/** @var string|null $logo_data_url */
/** @var string|null $generated_at_text */
/** @var string|null $period_label */
/** @var string|null $service_label */
/** @var string|null $status_label */
/** @var array|null $teachers */
$teachers = $teachers ?? [];
$teacherCount = count($teachers);
$schoolName = $school_name ?: 'Forscherhaus Grundschule';
$generatedAt = $generated_at_text ?? date('d.m.Y, H:i');
$periodLabel = $period_label ?? '';
$serviceLabel = $service_label ?? '';
$statusLabel = $status_label ?? '';
$title = lang('dashboard_teacher_pdf_title') ?: 'Lehrkräfte-Report';
$progressLabel = lang('dashboard_teacher_pdf_progress_title') ?: 'Fortschritt Klassenleitungssprechtage';
$classSizeLabel = lang('dashboard_teacher_pdf_metric_class_size') ?: 'Klassengröße';
$bookedLabel = lang('dashboard_teacher_pdf_metric_booked') ?: 'Gebucht';
$openLabel = lang('dashboard_teacher_pdf_metric_open') ?: 'Offen';
$slotsLabel = lang('dashboard_teacher_pdf_metric_slots') ?: 'Slots geplant / benötigt';
$tableTitle = lang('dashboard_teacher_pdf_table_heading') ?: 'Terminübersicht';
$parentHeader = lang('dashboard_teacher_pdf_table_parent') ?: 'Nachname Eltern';
$dateHeader = lang('dashboard_teacher_pdf_table_date') ?: 'Datum';
$startHeader = lang('dashboard_teacher_pdf_table_start') ?: 'Start';
$endHeader = lang('dashboard_teacher_pdf_table_end') ?: 'Ende';
$outreachTitle = lang('dashboard_teacher_pdf_outreach_title') ?: 'Outreach';
$outreachStatusLabel = lang('dashboard_teacher_pdf_outreach_current_status') ?: 'Aktueller Stand';
$sduiLabel = lang('dashboard_teacher_pdf_outreach_variant_sdui') ?: 'Vorschlag für Sdui';
$checklistLabel = lang('dashboard_teacher_pdf_outreach_checklist_title') ?: 'Mini-Checkliste';
$checklistStepOne = lang('dashboard_teacher_pdf_checklist_step_one') ?: 'Erinnerung gesendet';
$checklistStepTwo = lang('dashboard_teacher_pdf_checklist_step_two') ?: 'Telefonversuch dokumentiert';
$checklistStepThree = lang('dashboard_teacher_pdf_checklist_step_three') ?: 'Alternative Zeiten angeboten';
$noDataLabel = lang('dashboard_teacher_pdf_empty') ?: 'Keine Lehrkräfte für die ausgewählten Filter vorhanden.';
$maxAppointmentsFirstPage = 10;
$maxAppointmentsContinuation = 8;
$maxAppointmentsFinalPage = 8;
$teacherPages = [];
$pageCount = 1;
$timeSuffixRaw = lang('pdf_export_time_suffix');
$timeSuffixLabel = is_string($timeSuffixRaw) ? trim($timeSuffixRaw) : '';
$timeFormatSetting = setting('time_format') ?: 'military';
$appendTimeSuffix = $timeSuffixLabel !== '' && $timeFormatSetting === 'military';

foreach ($teachers as $teacherIndex => $teacherData) {
    $appointmentsAll = array_values($teacherData['appointments'] ?? []);
    $hasAnyAppointments = !empty($appointmentsAll);

    if ($hasAnyAppointments) {
        $firstChunk = array_splice($appointmentsAll, 0, $maxAppointmentsFirstPage);
        $chunks = [$firstChunk];

        while (!empty($appointmentsAll)) {
            $chunks[] = array_splice($appointmentsAll, 0, $maxAppointmentsContinuation);
        }

        while (!empty($chunks)) {
            $lastIndex = count($chunks) - 1;

            if (count($chunks[$lastIndex]) <= $maxAppointmentsFinalPage) {
                break;
            }

            $overflow = array_splice($chunks[$lastIndex], $maxAppointmentsFinalPage);

            if (empty($overflow)) {
                break;
            }

            $chunks[] = $overflow;
        }
    } else {
        $chunks = [[]];
    }

    $chunksTotal = count($chunks);

    foreach ($chunks as $chunkIndex => $chunkAppointments) {
        $teacherPages[] = [
            'teacher' => $teacherData,
            'teacher_index' => $teacherIndex,
            'chunk_index' => $chunkIndex,
            'chunks_total' => $chunksTotal,
            'appointments' => $chunkAppointments,
            'has_any_appointments' => $hasAnyAppointments,
        ];
    }
}

if ($teacherCount > 0) {
    $pageCount = max(1, count($teacherPages));
}
?>
<?php if ($teacherCount === 0): ?>
  <div class="page">
    <header class="header">
      <div class="header__titles">
        <h1 class="header__title"><?= html_escape($title) ?></h1>
        <?php if ($periodLabel): ?>
          <p class="header__meta">Übersicht zu den Klassenleitungssprechtagen (<?= html_escape($periodLabel) ?>)</p>
        <?php endif; ?>
      </div>
      <?php if (!empty($logo_data_url)): ?>
        <img src="<?= html_escape($logo_data_url) ?>" alt="<?= html_escape($schoolName) ?>" class="logo header__logo" />
      <?php endif; ?>
    </header>
    <p class="open-summary"><?= html_escape($noDataLabel) ?></p>
    <footer class="footer">
      <span>Stand: <?= html_escape($generatedAt) ?></span>
      <span>1/1</span>
    </footer>
  </div>
<?php else: ?>
  <?php foreach ($teacherPages as $pageIndex => $pageData):

      $pageNumber = $pageIndex + 1;
      $teacher = $pageData['teacher'];
      $chunkIndex = (int) $pageData['chunk_index'];
      $chunksTotal = (int) $pageData['chunks_total'];
      $appointments = $pageData['appointments'];
      $hasAppointmentsChunk = !empty($appointments);
      $hasAnyAppointments = (bool) ($pageData['has_any_appointments'] ?? false);
      $isFirstChunk = $chunkIndex === 0;
      $isContinuation = $chunkIndex > 0;
      $isLastChunk = $chunkIndex === $chunksTotal - 1;
      $bookedPercent = (float) ($teacher['progress']['booked_percent'] ?? 0);
      $openPercent = (float) ($teacher['progress']['open_percent'] ?? 0);
      $slotInfo = $teacher['slot_info_text'] ?? '';
      $teacherPageNumber = $chunkIndex + 1;
      $teacherPageCount = max(1, $chunksTotal);
      ?>
    <div class="page<?= $pageNumber > 1 ? ' page--break' : '' ?>">
      <header class="header">
        <div class="header__titles">
          <h1 class="header__title"><?= html_escape($title) ?></h1>
          <?php if ($periodLabel): ?>
            <p class="header__meta">Übersicht zu den Klassenleitungssprechtagen (<?= html_escape($periodLabel) ?>)</p>
          <?php endif; ?>
        </div>
        <?php if (!empty($logo_data_url)): ?>
          <img src="<?= html_escape($logo_data_url) ?>" alt="<?= html_escape(
    $schoolName,
) ?>" class="logo header__logo" />
        <?php endif; ?>
      </header>

      <section class="teacher">
        <?php if ($isFirstChunk): ?>
          <div class="teacher__heading">
            <h2 class="teacher__name"><?= html_escape($teacher['provider_name'] ?? '') ?></h2>
          </div>
        <?php endif; ?>

        <?php if ($isFirstChunk): ?>
          <article class="kpi-card" aria-label="<?= html_escape($progressLabel) ?>">
            <h3><?= html_escape($progressLabel) ?></h3>
            <div class="progress">
              <div class="progress__bar" role="img" aria-label="<?= html_escape($slotInfo) ?>">
                <div class="progress__booked" style="width: <?= $bookedPercent ?>%;"></div>
                <div class="progress__open" style="width: <?= $openPercent ?>%;"></div>
              </div>
              <div class="progress__labels">
                <span><span class="legend__dot legend__dot--booked"></span><?= html_escape(
                    $bookedLabel,
                ) ?>: <?= html_escape($teacher['booked_formatted'] ?? '0') ?></span>
                <span><span class="legend__dot legend__dot--open"></span><?= html_escape(
                    $openLabel,
                ) ?>: <?= html_escape($teacher['open_formatted'] ?? '0') ?></span>
              </div>
            </div>
          </article>

          <div class="metrics-row">
            <div class="metric">
              <span><?= html_escape($classSizeLabel) ?></span>
              <strong><?= html_escape($teacher['target_formatted'] ?? '—') ?></strong>
            </div>
            <div class="metric">
              <span><?= html_escape($bookedLabel) ?></span>
              <div class="metric__value">
                <strong><?= html_escape($teacher['booked_formatted'] ?? '0') ?></strong>
                <span class="metric__suffix">(<?= html_escape($teacher['booked_percent_formatted'] ?? '0 %') ?>)</span>
              </div>
            </div>
            <div class="metric">
              <span><?= html_escape($openLabel) ?></span>
              <strong><?= html_escape($teacher['open_formatted'] ?? '0') ?></strong>
            </div>
            <div class="metric">
              <span><?= html_escape($slotsLabel) ?></span>
              <strong><?= html_escape(
                  ($teacher['slots_planned_formatted'] ?? '—') . ' / ' . ($teacher['slots_required_formatted'] ?? '—'),
              ) ?></strong>
            </div>
          </div>
        <?php endif; ?>

        <section class="card" aria-label="<?= html_escape($tableTitle) ?>">
          <h3><?= html_escape($tableTitle) ?></h3>
          <?php if ($hasAppointmentsChunk): ?>
            <table>
              <thead>
                <tr>
                  <th class="col-parent"><?= html_escape($parentHeader) ?></th>
                  <th class="col-date"><?= html_escape($dateHeader) ?></th>
                  <th class="col-start"><?= html_escape($startHeader) ?></th>
                  <th class="col-end"><?= html_escape($endHeader) ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($appointments as $appointment):

                    $parentLastName = $appointment['parent_lastname'] ?? '';
                    $dateDisplay = $appointment['date'] ?? '';
                    $startDisplay = $appointment['start'] ?? '';
                    $endDisplay = $appointment['end'] ?? '';
                    if ($appendTimeSuffix && $startDisplay !== '') {
                        $startDisplay = rtrim($startDisplay) . ' ' . $timeSuffixLabel;
                    }
                    if ($appendTimeSuffix && $endDisplay !== '') {
                        $endDisplay = rtrim($endDisplay) . ' ' . $timeSuffixLabel;
                    }
                    ?>
                  <tr>
                    <td><?= html_escape($parentLastName) ?></td>
                    <td><?= html_escape($dateDisplay) ?></td>
                    <td><?= html_escape($startDisplay) ?></td>
                    <td><?= html_escape($endDisplay) ?></td>
                  </tr>
                <?php
                endforeach; ?>
              </tbody>
            </table>
          <?php elseif (!$hasAnyAppointments && $isLastChunk): ?>
            <p class="open-summary"><?= html_escape(
                lang('dashboard_teacher_pdf_no_appointments') ?: 'Keine Termine im Zeitraum.',
            ) ?></p>
          <?php endif; ?>
        </section>

        <?php if ($isLastChunk): ?>
        <?php endif; ?>
      </section>

      <footer class="footer">
        <span>Stand: <?= html_escape($generatedAt) ?></span>
        <span><?= $teacherPageNumber ?>/<?= $teacherPageCount ?></span>
      </footer>
    </div>
  <?php
  endforeach; ?>
<?php endif; ?>
<script>window.chartsReady = true;</script>
</body>
</html>
