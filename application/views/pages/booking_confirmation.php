<?php extend('layouts/message_layout'); ?>

<?php section('styles'); ?>
<style>
    .booking-confirmation-wrapper {
        width: 100%;
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 clamp(16px, 3vw, 24px);
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }

    .booking-confirmation-banner {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
        align-items: center;
        text-align: center;
    }

    .booking-banner-title {
        font-size: 1.625rem;
        font-weight: 700;
        letter-spacing: -0.01em;
        color: #1f2933;
        hyphens: manual;
        -webkit-hyphens: manual;
        overflow-wrap: normal;
        word-break: normal;
    }

    .booking-banner-text {
        font-size: 1.05rem;
        line-height: 1.6;
        color: #3c4858;
        margin: 0;
        display: none;
    }

    .booking-confirmation-grid {
        display: flex;
        flex-direction: column;
        gap: 1.75rem;
    }

    .booking-column {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        min-width: 0;
    }

    .booking-summary-card {
        width: 100%;
    }

    .booking-confirmation-summary {
        margin: 0;
        display: grid;
        gap: 1.25rem;
        grid-template-columns: minmax(0, 1fr);
    }

    .booking-confirmation-summary > *,
    .booking-summary-card,
    .booking-column > * {
        min-width: 0;
    }

    .booking-confirmation-summary .summary-title {
        color: #3c4858 !important;
        display: block;
        hyphens: auto;
        -webkit-hyphens: auto;
        overflow-wrap: break-word;
        word-break: normal;
    }

    .booking-confirmation-summary .summary-column {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
        text-align: left;
    }

    .booking-confirmation-summary .summary-manage-column {
        gap: 1rem;
    }

    .booking-confirmation-summary .summary-details {
        gap: 0.5rem;
        align-items: flex-start;
    }

    .booking-confirmation-summary .summary-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 1rem;
        line-height: 1.4;
        color: #3c4858;
    }

    .booking-confirmation-summary .summary-item span {
        word-break: break-word;
    }

    .booking-manage-card {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        align-items: stretch;
    }

    .btn-manage {
        background-color: #343a40;
        color: #ffffff;
        border-color: #343a40;
        min-height: 44px;
        padding: 0.6rem 1.2rem;
        width: 100%;
        text-transform: none;
    }

    .btn-manage:hover,
    .btn-manage:focus {
        background-color: #23272b;
        border-color: #23272b;
        color: #ffffff;
    }

    .btn-ghost {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
        border: none;
        background: transparent;
        color: #3c4858;
        padding: 0.45rem 0.75rem;
        font-size: 0.95rem;
        text-decoration: underline;
        min-height: 42px;
        flex: 1 1 0;
        font-weight: 600;
        text-transform: none;
        white-space: nowrap;
    }

    .btn-ghost:hover,
    .btn-ghost:focus {
        text-decoration: none;
        color: #1f2933;
        background-color: rgba(60, 72, 88, 0.08);
        border-radius: 0.75rem;
    }

    .btn-ghost .icon {
        font-size: 1rem;
        color: inherit;
    }

    .btn-ghost .label {
        text-transform: none;
    }

    .booking-manage-hint {
        font-size: 0.95rem;
        line-height: 1.6;
        color: #3c4858;
        margin: 0;
        max-width: 48ch;
        text-align: left;
    }

    .booking-manage-actions {
        display: flex;
        flex-direction: column;
        gap: 0.6rem;
    }

    .booking-manage-actions .action-row {
        display: flex;
        gap: 0.9rem;
        align-items: stretch;
    }

    .booking-manage-actions .action-row .btn {
        flex: 1 1 calc(50% - 0.45rem);
        max-width: calc(100% - 0.45rem);
    }

    .booking-calendar-card {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .booking-utilities-card {
        gap: 1.25rem;
    }

    .booking-calendar-card .btn-toggle {
        width: 100%;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        min-height: 44px;
        padding: 0.6rem 1.2rem;
    }

    .booking-calendar-card .btn-toggle span {
        flex: 1 1 auto;
        text-align: center;
    }

    .booking-calendar-card .btn-toggle .icon {
        font-size: 1.25rem;
    }

    .booking-calendar-card .btn-toggle .caret {
        margin-left: auto;
        font-size: 0.9rem;
    }

    .calendar-links-collapse {
        margin-top: 0.75rem;
    }

    .calendar-links-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .calendar-links-list a {
        display: block;
        padding: 0.5rem 0;
        color: #1f2933;
        text-decoration: none;
        border-bottom: 1px solid #d7dee8;
        text-align: left;
    }

    .calendar-links-list a:last-child {
        border-bottom: none;
    }

    .calendar-links-list a:hover,
    .calendar-links-list a:focus {
        text-decoration: underline;
    }

    .booking-secondary-link {
        font-weight: 500;
        text-align: center;
        display: inline-block;
        margin-top: 0.5rem;
        color: #343a40;
        text-decoration: underline;
    }

    .booking-secondary-link:hover,
    .booking-secondary-link:focus {
        text-decoration: none;
        color: #1f2933;
    }

    .copy-toast {
        position: fixed;
        bottom: 1rem;
        left: 50%;
        transform: translateX(-50%);
        background-color: #1f2933;
        color: #ffffff;
        padding: 0.75rem 1.25rem;
        border-radius: 999px;
        box-shadow: 0 10px 20px rgba(15, 23, 42, 0.18);
        opacity: 0;
        pointer-events: none;
        transition: opacity 150ms ease-in-out;
        font-size: 0.95rem;
        z-index: 1050;
    }

    .copy-toast.is-visible {
        opacity: 1;
    }

    @media (max-width: 599px) {
        .booking-manage-actions {
            align-items: flex-start;
            gap: 0.5rem;
            width: 100%;
        }

        .booking-manage-actions .action-row {
            flex-direction: column;
            align-items: stretch;
            gap: 0.4rem;
        }

        .booking-manage-actions .action-row .btn {
            flex: 0 0 auto;
            max-width: 100%;
            width: 100%;
            justify-content: flex-start;
            text-align: left;
        }

        .btn-ghost {
            justify-content: flex-start;
            text-align: left;
            white-space: normal;
        }

        .btn-ghost .label {
            text-align: left;
        }
    }

    @media (max-width: 400px) {
        .booking-confirmation-wrapper {
            padding: 0 0.75rem;
        }

        .booking-confirmation-summary {
            gap: 1rem;
        }

        .booking-confirmation-summary .summary-title {
            font-size: 1.2rem;
            letter-spacing: -0.01em;
        }

        .booking-confirmation-summary .summary-item {
            font-size: 0.95rem;
        }

        .booking-manage-actions .action-row {
            gap: 0.75rem;
        }

        .booking-banner-title {
            font-size: 1.35rem;
        }
    }

    @media (max-width: 360px) {
        .booking-confirmation-summary {
            gap: 0.9rem;
        }

        .booking-confirmation-summary .summary-column {
            align-items: stretch;
        }

        .booking-manage-actions .action-row {
            flex-direction: column;
        }

        .booking-manage-actions .action-row .btn {
            flex: 1 1 100%;
            max-width: 100%;
        }

        .booking-calendar-card .btn-toggle {
            justify-content: space-between;
        }

        .booking-calendar-card .btn-toggle span {
            text-align: left;
        }

        .booking-banner-title {
            font-size: 1.25rem;
        }
    }

    @media (max-width: 340px) {
        .btn-manage,
        .booking-calendar-card .btn-toggle {
            font-size: 0.95rem;
            padding: 0.55rem 0.9rem;
        }

        .booking-manage-actions .action-row {
            gap: 0.65rem;
        }

        .booking-banner-title {
            font-size: 1.15rem;
        }
    }

    @media (min-width: 600px) and (max-width: 1023px) {
        .booking-manage-hint {
            max-width: none;
            width: 100%;
        }

        .booking-manage-actions {
            align-items: stretch;
        }

        .booking-utilities-card {
            gap: 1.5rem;
        }
    }

    @media (min-width: 768px) {
        .booking-confirmation-wrapper {
            padding-inline: clamp(24px, 3vw, 32px);
        }
    }

    @media (min-width: 1024px) {
        .booking-confirmation-wrapper {
            gap: 2rem;
            padding-inline: clamp(24px, 3vw, 36px);
        }

        .booking-confirmation-grid {
            display: grid;
            grid-template-columns: minmax(0, clamp(440px, 44vw, 540px)) minmax(0, clamp(480px, 46vw, 600px));
            gap: clamp(2.5rem, 3.5vw, 3.5rem);
            align-items: start;
            justify-content: center;
        }

        .booking-column {
            gap: 1.75rem;
        }

        .booking-manage-card {
            align-items: flex-start;
            text-align: left;
        }

        .booking-manage-card .btn-manage {
            width: auto;
        }

        .booking-manage-hint {
            max-width: 42ch;
            width: 100%;
        }

        .booking-manage-actions {
            align-items: flex-start;
            gap: 0.75rem;
            width: 100%;
        }

        .booking-manage-actions .action-row {
            justify-content: flex-start;
            gap: 0.5rem 0.75rem;
            flex-wrap: wrap;
            width: 100%;
        }

        .booking-manage-actions .action-row .btn {
            flex: 0 1 auto;
            min-width: 0;
            padding-left: 0.65rem;
            padding-right: 0.65rem;
        }

        .booking-calendar-card {
            align-items: flex-start;
        }

        .booking-calendar-card .btn-toggle {
            width: auto;
            align-self: flex-start;
        }

        .booking-confirmation-banner {
            align-items: flex-start;
            text-align: left;
            gap: 0.5rem;
        }

        .booking-banner-text {
            display: block;
        }

        .booking-column-primary {
            max-width: 560px;
            width: 100%;
        }

        .booking-column-secondary {
            max-width: 600px;
            width: 100%;
        }
    }

    @media (min-width: 1280px) {
        .booking-confirmation-wrapper {
            padding-inline: clamp(32px, 4vw, 40px);
        }
    }
</style>
<?php end_section('styles'); ?>

<?php section('content'); ?>

<?php
$appointment_summary = vars('appointment_summary') ?? [];
$calendar_links = vars('calendar_links') ?? [];
$manage_url = vars('manage_url');
$is_manageable = vars('is_manageable');
$appointment_registered_short = lang('appointment_registered_short') ?: lang('appointment_registered');
$appointment_registered_message = lang('appointment_registered') ?: 'Ihr Termin ist erfolgreich registriert worden.';
$manage_appointment_cta = lang('manage_appointment_cta') ?: 'Manage appointment';
$manage_appointment_cta_locked = lang('manage_appointment_cta_locked') ?: lang('appointment_locked');
$manage_link_hint = lang('manage_link_hint') ?: 'Save the management link to change or cancel the appointment later.';
$manage_link_locked_hint =
    lang('manage_link_locked_hint') ?:
    (lang('appointment_locked_message') ?:
    'This appointment can no longer be changed online.');
$copy_link_button = lang('copy_link_button') ?: 'Copy link';
$share_link_button = lang('share_link_button') ?: 'Share';
$add_to_calendar_grouped = lang('add_to_calendar_grouped') ?: 'Add to calendar';
$book_another_link = lang('book_another_appointment_link') ?: lang('go_to_booking_page');
?>

<div class="booking-confirmation-wrapper">
    <div class="booking-confirmation-banner frame-content">
        <h4 class="booking-banner-title mb-1" lang="de"><?= $appointment_registered_short ?></h4>
        <p class="booking-banner-text mb-0" lang="de"><?= $appointment_registered_message ?></p>
    </div>

    <div class="booking-confirmation-grid">
        <div class="booking-column booking-column-primary">
            <?php $this->load->view('appointments/partials/_appointment_summary', [
                'appointment' => $appointment_summary,
                'customer' => [],
                'show_customer' => false,
                'wrapper_classes' => 'booking-confirmation-summary frame-content booking-summary-card',
                'appointment_column_classes' => 'summary-column summary-details-column',
                'customer_details_id' => 'booking-manage-summary-details',
                'customer_column_classes' => 'summary-column summary-manage-column',
            ]); ?>

            <div class="booking-manage-card frame-content booking-manage-primary">
                <?php if ($is_manageable): ?>
                    <a href="<?= htmlspecialchars($manage_url, ENT_QUOTES, 'UTF-8') ?>"
                       class="btn btn-manage btn-large"
                       rel="noopener">
                        <?= $manage_appointment_cta ?>
                    </a>
                <?php else: ?>
                    <span class="btn btn-outline-secondary btn-large disabled" role="button" aria-disabled="true" tabindex="-1">
                        <?= $manage_appointment_cta_locked ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="booking-column booking-column-secondary">
            <div class="booking-manage-card frame-content booking-utilities-card">
                <p class="booking-manage-hint">
                    <?= $is_manageable ? $manage_link_hint : $manage_link_locked_hint ?>
                </p>

                <div class="booking-manage-actions">
                    <div class="action-row">
                        <button type="button"
                                class="btn btn-ghost copy-button"
                                data-copy-target="<?= htmlspecialchars($manage_url, ENT_QUOTES, 'UTF-8') ?>">
                            <i class="fas fa-link icon" aria-hidden="true"></i>
                            <span class="label"><?= $copy_link_button ?></span>
                        </button>
                        <button type="button"
                                class="btn btn-ghost share-button"
                                data-share-url="<?= htmlspecialchars($manage_url, ENT_QUOTES, 'UTF-8') ?>">
                            <i class="fas fa-share-alt icon" aria-hidden="true"></i>
                            <span class="label"><?= $share_link_button ?></span>
                        </button>
                    </div>
                    <span class="visually-hidden"><?= htmlspecialchars($manage_url, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>

            <?php if (!vars('is_past_event')): ?>
                <div class="booking-calendar-card frame-content">
                    <button class="btn btn-outline-primary btn-toggle" type="button" data-bs-toggle="collapse"
                            data-bs-target="#calendarLinksCollapse" aria-expanded="false" aria-controls="calendarLinksCollapse">
                        <i class="fas fa-calendar-day icon" aria-hidden="true"></i>
                        <span><?= $add_to_calendar_grouped ?></span>
                        <i class="fas fa-chevron-down caret" aria-hidden="true"></i>
                    </button>

                    <div class="collapse calendar-links-collapse" id="calendarLinksCollapse">
                        <div class="calendar-links-list">
                            <?php if (!empty($calendar_links['google'])): ?>
                                <a href="<?= htmlspecialchars($calendar_links['google'], ENT_QUOTES, 'UTF-8') ?>"
                                   target="_blank" rel="noopener"><?= lang('add_to_google_calendar') ?></a>
                            <?php endif; ?>

                            <?php if (!empty($calendar_links['outlook'])): ?>
                                <a href="<?= htmlspecialchars($calendar_links['outlook'], ENT_QUOTES, 'UTF-8') ?>"
                                   target="_blank" rel="noopener"><?= lang('add_to_outlook_calendar') ?></a>
                            <?php endif; ?>

                            <?php if (!empty($calendar_links['ics'])): ?>
                                <a href="<?= htmlspecialchars($calendar_links['ics'], ENT_QUOTES, 'UTF-8') ?>"><?= lang(
    'add_to_apple_calendar',
) ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <a href="<?= site_url() ?>" class="booking-secondary-link">
        <?= $book_another_link ?>
    </a>
</div>

<div class="copy-toast visually-hidden" data-copy-toast role="status" aria-live="polite" aria-atomic="true"></div>

<?php end_section('content'); ?>

<?php section('scripts'); ?>
<script>
    (function () {
        const manageUrl = <?= json_encode($manage_url) ?>;
        const buttons = document.querySelectorAll('[data-copy-target]');
        const shareButtons = document.querySelectorAll('[data-share-url]');
        const toast = document.querySelector('[data-copy-toast]');
        const toastMessageCopied = <?= json_encode(lang('link_copied_confirmation') ?: 'Link copied') ?>;
        const toastMessageFallback = <?= json_encode(
            lang('copy_link_fallback') ?: 'Copy failed. Please copy the link manually.',
        ) ?>;
        const shareUnavailableMessage = <?= json_encode(
            lang('share_link_unavailable') ?: 'Sharing is not available on this device.',
        ) ?>;
        const shareTitle = <?= json_encode(lang('share_link_title') ?: 'Appointment details') ?>;
        const shareText = <?= json_encode(
            lang('share_link_text') ?: 'Change or cancel the appointment via this link.',
        ) ?>;

        const showToast = (message) => {
            if (!toast) {
                return;
            }

            toast.textContent = message;
            toast.classList.remove('visually-hidden');
            toast.classList.add('is-visible');

            setTimeout(() => {
                toast.classList.remove('is-visible');
                toast.textContent = '';
                toast.classList.add('visually-hidden');
            }, 3000);
        };

        buttons.forEach((button) => {
            button.addEventListener('click', () => {
                const value = button.getAttribute('data-copy-target');

                if (!value) {
                    return;
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(value).then(() => {
                        showToast(toastMessageCopied);
                    }).catch(() => {
                        showToast(toastMessageFallback);
                    });
                } else {
                    const tempInput = document.createElement('input');
                    tempInput.type = 'text';
                    tempInput.value = value;
                    document.body.appendChild(tempInput);
                    tempInput.select();
                    document.execCommand('copy');
                    document.body.removeChild(tempInput);
                    showToast(toastMessageFallback);
                }
            });
        });

        shareButtons.forEach((button) => {
            button.addEventListener('click', async () => {
                const url = button.getAttribute('data-share-url') || manageUrl;

                if (navigator.share) {
                    try {
                        await navigator.share({
                            title: shareTitle,
                            text: shareText,
                            url
                        });
                    } catch (error) {
                        if (!error || error.name === 'AbortError') {
                            return;
                        }

                        showToast(shareUnavailableMessage);
                    }
                } else {
                    showToast(shareUnavailableMessage);
                }
            });
        });
    })();
</script>

<?php component('google_analytics_script', ['google_analytics_code' => vars('google_analytics_code')]); ?>
<?php component('matomo_analytics_script', [
    'matomo_analytics_url' => vars('matomo_analytics_url'),
    'matomo_analytics_site_id' => vars('matomo_analytics_site_id'),
]); ?>

<?php end_section('scripts'); ?>
