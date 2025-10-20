<?php extend('layouts/backend_layout'); ?>

<?php section('content'); ?>

<div class="container-fluid backend-page" id="dashboard-page">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form id="dashboard-filters" class="row gy-3 align-items-end dashboard-filters">
                        <div class="col-12 col-lg-3">
                            <label class="form-label" for="dashboard-date-range">
                                <?= lang('date_range') ?>
                            </label>
                            <input type="text" id="dashboard-date-range" class="form-control" autocomplete="off">
                        </div>
                        <div class="col-12 col-lg-3">
                            <label class="form-label" for="dashboard-statuses">
                                <?= lang('statuses_filter_label') ?>
                            </label>
                            <select id="dashboard-statuses" class="form-select" multiple></select>
                        </div>
                        <div class="col-12 col-lg-3">
                            <label class="form-label" for="dashboard-service">
                                <?= lang('service') ?>
                            </label>
                            <select id="dashboard-service" class="form-select"></select>
                        </div>
                        <div class="col-12 col-lg-3">
                            <div class="d-flex flex-wrap gap-2 align-items-end justify-content-lg-end">
                                <div class="dropdown" data-bs-auto-close="outside">
                                    <button class="btn btn-outline-secondary w-100 w-lg-auto dropdown-toggle" type="button" id="dashboard-options-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fas fa-sliders-h me-2"></i>
                                        <?= lang('dashboard_options') ?>
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-end dashboard-options-dropdown" aria-labelledby="dashboard-options-toggle">
                                        <div class="px-3 py-2 d-none">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="dashboard-hide-without-target">
                                                <label class="form-check-label d-flex align-items-center gap-2" for="dashboard-hide-without-target">
                                                    <span><?= lang('dashboard_hide_without_target') ?></span>
                                                    <span id="dashboard-hidden-counter" class="text-muted small" data-pattern="<?= lang(
                                                        'dashboard_hidden_counter_pattern',
                                                    ) ?>"></span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="dropdown-divider d-none"></div>
                                        <button type="button" class="dropdown-item d-flex align-items-center gap-2" id="dashboard-download-teacher">
                                            <i class="fas fa-file-download text-muted"></i>
                                            <span><?= lang('dashboard_download_teacher_pdf') ?></span>
                                        </button>
                                        <button type="button" class="dropdown-item d-flex align-items-center gap-2" id="dashboard-download-principal">
                                            <i class="fas fa-file-download text-muted"></i>
                                            <span><?= lang('dashboard_download_principal_pdf') ?></span>
                                        </button>
                                        <button type="button" class="dropdown-item d-flex align-items-center gap-2" id="dashboard-threshold-button">
                                            <i class="fas fa-bullseye text-muted"></i>
                                            <span><?= lang('dashboard_conflict_threshold') ?></span>
                                            <span class="badge bg-light text-dark ms-auto" id="dashboard-threshold-display"></span>
                                        </button>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary w-100 w-lg-auto ms-lg-auto">
                                    <i class="fas fa-sync-alt me-2"></i>
                                    <?= lang('refresh') ?>
                                </button>
                            </div>
                        </div>
                    </form>
                    <div id="dashboard-error" class="alert alert-danger mt-3" role="alert" hidden></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0 fw-light">
                        <?= lang('utilization') ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="utilization-chart" height="320"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0 fw-light">
                        <?= lang('providers') ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="dashboard-table">
                            <thead>
                                <tr>
                                    <th><?= lang('provider') ?></th>
                                    <th><?= lang('class_size_default') ?></th>
                                    <th><?= lang('booked') ?></th>
                                    <th><?= lang('open') ?></th>
                                    <th><?= lang('fill_rate') ?></th>
                                    <th><?= lang('status') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr id="dashboard-table-empty" class="text-muted">
                                    <td colspan="6" class="text-center">
                                        <?= lang('no_records_found') ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="dashboard-threshold-modal" tabindex="-1" aria-labelledby="dashboard-threshold-modal-label" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="dashboard-threshold-form">
            <div class="modal-header">
                <h5 class="modal-title" id="dashboard-threshold-modal-label">
                    <?= lang('dashboard_conflict_threshold') ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= lang(
                    'cancel',
                ) ?>"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="dashboard-threshold-input">
                    <?= lang('dashboard_conflict_threshold_hint') ?>
                </label>
                <input type="number" class="form-control" id="dashboard-threshold-input" min="0" max="1" step="0.05">
                <div class="invalid-feedback">
                    <?= lang('dashboard_conflict_threshold_invalid') ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <?= lang('cancel') ?>
                </button>
                <button type="submit" class="btn btn-primary">
                    <?= lang('dashboard_apply_threshold') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php end_section('content'); ?>

<?php section('scripts'); ?>
    <script src="<?= asset_url('assets/vendor/chart.js/chart.umd.min.js') ?>"></script>
    <script src="<?= asset_url('assets/js/http/dashboard_http_client.js') ?>"></script>
    <script src="<?= asset_url('assets/js/pages/dashboard.js') ?>"></script>
<?php end_section('scripts'); ?>
