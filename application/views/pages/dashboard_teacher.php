<?php extend('layouts/backend_layout'); ?>

<?php section('content'); ?>

<div class="container-fluid backend-page" id="dashboard-teacher-page">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="dashboard-teacher-title h4 mb-3">
                <?= lang('dashboard_teacher_dashboard_title') ?>
            </h1>
        </div>
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form id="dashboard-teacher-filters" class="row gy-3 align-items-end">
                        <div class="col-12 col-md-6 col-lg-4">
                            <label class="form-label" for="dashboard-teacher-date-range">
                                <?= lang('date_range') ?>
                            </label>
                            <input
                                type="text"
                                id="dashboard-teacher-date-range"
                                class="form-control"
                                autocomplete="off"
                            >
                        </div>
                        <div class="col-12 col-md-6 col-lg-4 ms-lg-auto">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-sync-alt me-2"></i>
                                <?= lang('refresh') ?>
                            </button>
                        </div>
                    </form>

                    <div id="dashboard-teacher-error" class="alert alert-danger mt-3" role="alert" hidden></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card dashboard-teacher-progress-card">
                <div class="card-body">
                    <h2 class="h6 dashboard-teacher-section-heading mb-3">
                        <?= lang('dashboard_teacher_pdf_progress_title') ?>
                    </h2>
                    <div class="dashboard-teacher-progress">
                        <div class="dashboard-teacher-progress-bar" role="img" aria-label="<?= lang(
                            'dashboard_teacher_progress_aria_label',
                        ) ?>">
                            <div
                                class="dashboard-teacher-progress-booked"
                                id="dashboard-teacher-progress-booked"
                                style="width: 0%;"
                            ></div>
                            <div
                                class="dashboard-teacher-progress-open"
                                id="dashboard-teacher-progress-open"
                                style="width: 0%;"
                            ></div>
                        </div>
                        <div class="dashboard-teacher-progress-labels">
                            <span>
                                <span class="dashboard-teacher-dot dashboard-teacher-dot-booked"></span>
                                <?= lang('dashboard_teacher_pdf_metric_booked') ?>:
                                <strong id="dashboard-teacher-progress-booked-value">0</strong>
                            </span>
                            <span>
                                <span class="dashboard-teacher-dot dashboard-teacher-dot-open"></span>
                                <?= lang('dashboard_teacher_pdf_metric_open') ?>:
                                <strong id="dashboard-teacher-progress-open-value">0</strong>
                            </span>
                        </div>
                        <p class="dashboard-teacher-slot-info mb-0" id="dashboard-teacher-slot-info"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card dashboard-teacher-metric-card h-100">
                <div class="card-body">
                    <span class="dashboard-teacher-metric-label"><?= lang(
                        'dashboard_teacher_pdf_metric_class_size',
                    ) ?></span>
                    <strong class="dashboard-teacher-metric-value" id="dashboard-teacher-class-size">—</strong>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dashboard-teacher-metric-card h-100">
                <div class="card-body">
                    <span class="dashboard-teacher-metric-label"><?= lang(
                        'dashboard_teacher_pdf_metric_booked',
                    ) ?></span>
                    <strong class="dashboard-teacher-metric-value" id="dashboard-teacher-booked">0</strong>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dashboard-teacher-metric-card h-100">
                <div class="card-body">
                    <span class="dashboard-teacher-metric-label"><?= lang('dashboard_teacher_pdf_metric_open') ?></span>
                    <strong class="dashboard-teacher-metric-value" id="dashboard-teacher-open">0</strong>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dashboard-teacher-metric-card h-100">
                <div class="card-body">
                    <span class="dashboard-teacher-metric-label"><?= lang(
                        'dashboard_teacher_pdf_metric_slots',
                    ) ?></span>
                    <strong class="dashboard-teacher-metric-value" id="dashboard-teacher-slots">0 / 0</strong>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6 dashboard-teacher-section-heading mb-3">
                        <?= lang('dashboard_teacher_appointments_heading') ?>
                    </h2>

                    <div class="dashboard-teacher-empty text-muted" id="dashboard-teacher-empty" hidden>
                        <?= lang('dashboard_teacher_no_appointments') ?>
                    </div>

                    <div class="dashboard-teacher-mobile-list d-md-none" id="dashboard-teacher-mobile-list"></div>

                    <div class="table-responsive d-none d-md-block" id="dashboard-teacher-table-wrapper">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th><?= lang('dashboard_teacher_pdf_table_parent') ?></th>
                                    <th><?= lang('dashboard_teacher_pdf_table_date') ?></th>
                                    <th><?= lang('dashboard_teacher_pdf_table_start') ?></th>
                                    <th><?= lang('dashboard_teacher_pdf_table_end') ?></th>
                                </tr>
                            </thead>
                            <tbody id="dashboard-teacher-table-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php end_section('content'); ?>

<?php section('scripts'); ?>
    <script src="<?= asset_url('assets/js/http/dashboard_http_client.js') ?>"></script>
    <script src="<?= asset_url('assets/js/pages/dashboard_teacher.js') ?>"></script>
<?php end_section('scripts'); ?>
