<?php extend('layouts/backend_layout'); ?>

<?php section('content'); ?>

<div class="container-fluid backend-page" id="dashboard-page">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form id="dashboard-filters" class="row gy-3 align-items-end dashboard-filters">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="dashboard-date-range">
                                <?= lang('date_range') ?>
                            </label>
                            <input type="text" id="dashboard-date-range" class="form-control" autocomplete="off">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="dashboard-statuses">
                                <?= lang('statuses_filter_label') ?>
                            </label>
                            <select id="dashboard-statuses" class="form-select" multiple></select>
                        </div>
                        <div class="col-12 col-md-4 d-flex flex-column flex-md-row align-items-start align-items-md-center gap-2">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" id="dashboard-hide-without-plan">
                                <label class="form-check-label" for="dashboard-hide-without-plan">
                                    <?= lang('hide_providers_without_plan') ?>
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary ms-md-auto">
                                <i class="fas fa-sync-alt me-2"></i>
                                <?= lang('refresh') ?>
                            </button>
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
                                    <th><?= lang('total') ?></th>
                                    <th><?= lang('booked_slots') ?></th>
                                    <th><?= lang('open_slots') ?></th>
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

<?php end_section(); ?>

<?php section('scripts'); ?>
    <script src="<?= asset_url('assets/vendor/chart.js/chart.umd.min.js') ?>"></script>
    <script src="<?= asset_url('assets/js/http/dashboard_http_client.js') ?>"></script>
    <script src="<?= asset_url('assets/js/pages/dashboard.js') ?>"></script>
<?php end_section(); ?>
