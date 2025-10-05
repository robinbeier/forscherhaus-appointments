<?php extend('layouts/message_layout'); ?>

<?php section('styles'); ?>
<style>
    .booking-confirmation-summary {
        max-width: 540px;
        margin: 0 auto 2.5rem;
    }

    .booking-confirmation-summary .summary-column {
        text-align: center;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0;
    }

    .booking-confirmation-summary .summary-details {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.35rem;
        margin-top: 0.15rem;
    }

    .booking-confirmation-summary .summary-subtitle {
        text-transform: none;
    }

    .booking-confirmation-summary .summary-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 1rem;
    }

    .booking-confirmation-summary .summary-item i {
        font-size: 1rem;
    }

    .calendar-action-buttons {
        display: grid;
        gap: 0.75rem;
        max-width: 360px;
        margin-bottom: 2rem;
    }

    .calendar-action-buttons .btn {
        width: 100%;
    }

    @media (min-width: 768px) {
        .calendar-action-buttons {
            margin-left: 0;
        }
    }
</style>
<?php end_section('styles'); ?>

<?php section('content'); ?>

<div>
    <img id="success-icon" class="mt-0 mb-5" src="<?= base_url('assets/img/success.png') ?>" alt="success"/>
</div>

<div class="mb-5">
    <h4 class="mb-5"><?= lang('appointment_registered') ?></h4>

    <h5 class="mb-3"><?= lang('appointment_overview_title') ?></h5>

    <?php
    $appointment_summary = vars('appointment_summary') ?? [];
    $calendar_links = vars('calendar_links') ?? [];
    ?>

    <?php $this->load->view('appointments/partials/_appointment_summary', [
        'appointment' => $appointment_summary,
        'customer' => [],
        'show_customer' => false,
        'wrapper_classes' => 'booking-confirmation-summary frame-content m-auto',
        'appointment_column_classes' => 'summary-column',
        'customer_column_classes' => 'summary-column',
    ]); ?>

    <?php if (!vars('is_past_event')): ?>
        <p class="mt-3 mb-4 text-center mx-auto" style="max-width: 440px;">
            <?= lang('open_calendar_options') ?>
        </p>

        <div class="calendar-action-buttons mx-auto">
            <?php if (!empty($calendar_links['google'])): ?>
                <a href="<?= htmlspecialchars($calendar_links['google'], ENT_QUOTES, 'UTF-8') ?>"
                   class="btn btn-outline-primary"
                   target="_blank" rel="noopener">
                    <i class="fab fa-google me-2"></i>
                    <?= lang('add_to_google_calendar') ?>
                </a>
            <?php endif; ?>

            <?php if (!empty($calendar_links['outlook'])): ?>
                <a href="<?= htmlspecialchars($calendar_links['outlook'], ENT_QUOTES, 'UTF-8') ?>"
                   class="btn btn-outline-primary"
                   target="_blank" rel="noopener">
                    <i class="far fa-calendar-plus me-2"></i>
                    <?= lang('add_to_outlook_calendar') ?>
                </a>
            <?php endif; ?>

            <?php if (!empty($calendar_links['ics'])): ?>
                <a href="<?= htmlspecialchars($calendar_links['ics'], ENT_QUOTES, 'UTF-8') ?>"
                   class="btn btn-outline-primary">
                    <i class="fas fa-calendar-day me-2"></i>
                    <?= lang('add_to_apple_calendar') ?>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <a href="<?= site_url() ?>" class="btn btn-primary btn-large">
        <i class="fas fa-calendar-alt me-2"></i>
        <?= lang('go_to_booking_page') ?>
    </a>
</div>

<?php end_section('content'); ?>

<?php section('scripts'); ?>

<?php component('google_analytics_script', ['google_analytics_code' => vars('google_analytics_code')]); ?>
<?php component('matomo_analytics_script', [
    'matomo_analytics_url' => vars('matomo_analytics_url'),
    'matomo_analytics_site_id' => vars('matomo_analytics_site_id'),
]); ?>

<?php end_section('scripts'); ?>
