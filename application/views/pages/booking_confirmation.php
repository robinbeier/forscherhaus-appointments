<?php extend('layouts/message_layout'); ?>

<?php section('styles'); ?>
<style>
    .booking-confirmation-wrapper {
        width: 100%;
        max-width: 1120px;
        margin: 0 auto;
        padding: clamp(24px, 4vw, 32px) clamp(16px, 5vw, 36px);
        display: flex;
        flex-direction: column;
        gap: clamp(1.5rem, 2vw, 2.25rem);
    }

    .booking-confirmation-banner {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        padding: 1.25rem clamp(16px, 4vw, 24px);
        border-radius: 1rem;
        background-color: rgba(120, 194, 173, 0.18);
        border: 1px solid rgba(120, 194, 173, 0.35);
        color: #1f2933;
    }

    .booking-banner-title {
        margin: 0;
        font-size: 1.375rem;
        font-weight: 600;
        letter-spacing: -0.01em;
        color: #1f2933;
        hyphens: manual;
        -webkit-hyphens: manual;
        overflow-wrap: normal;
        word-break: normal;
    }

    .booking-banner-text {
        margin: 0;
        font-size: 0.9375rem;
        line-height: 1.65;
        color: #3c4858;
    }

    .booking-confirmation-grid {
        display: flex;
        flex-direction: column;
        gap: clamp(1.5rem, 2vw, 2rem);
    }

    .booking-column {
        display: flex;
        flex-direction: column;
        gap: clamp(1.25rem, 1.8vw, 1.75rem);
        min-width: 0;
    }

    .booking-summary-card {
        width: 100%;
    }

    .booking-confirmation-summary {
        display: flex;
        flex-direction: column;
        gap: 1.15rem;
        margin: 0;
    }

    .booking-confirmation-summary > *,
    .booking-summary-card,
    .booking-column > * {
        min-width: 0;
    }

    .booking-confirmation-summary .summary-title {
        margin: 0;
        color: #1f2933 !important;
        font-size: 1.375rem;
        font-weight: 600;
        letter-spacing: -0.01em;
        hyphens: auto;
        -webkit-hyphens: auto;
        overflow-wrap: break-word;
        word-break: normal;
    }

    .booking-confirmation-summary .summary-subtitle {
        margin: 0;
        font-size: 0.9375rem;
        line-height: 1.65;
        color: #506175;
    }

    .booking-confirmation-summary .summary-column {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
        text-align: left;
    }

    .booking-confirmation-summary .summary-details {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        align-items: flex-start;
    }

    .booking-confirmation-summary .summary-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.9375rem;
        line-height: 1.5;
        font-weight: 500;
        color: #3c4858;
    }

    .booking-confirmation-summary .summary-item .fas {
        font-size: 1rem;
        color: #78c2ad;
    }

    .booking-confirmation-summary .summary-item span {
        word-break: break-word;
    }

    .booking-confirmation-summary .summary-item.summary-item--datetime span {
        font-weight: 500;
        color: #3c4858;
    }

    .booking-confirmation-summary .summary-item.summary-item--datetime .fas {
        color: #56cc9d;
    }

    .booking-manage-card {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        align-items: stretch;
        position: relative;
    }

    .booking-manage-hint {
        font-size: 0.9375rem;
        line-height: 1.65;
        color: #3c4858;
        margin: 0;
        text-align: left;
    }

    .booking-secure-actions {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        align-items: stretch;
    }

    .booking-calendar-actions {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
        align-items: stretch;
    }

    .booking-secure-actions .btn,
    .booking-secure-actions .dropdown-toggle {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        min-height: 48px;
        width: 100%;
        font-weight: 600;
        text-transform: none;
    }

    .booking-secure-actions .copy-button {
    max-height: 0px;
}


    .booking-secure-actions .btn .icon,
    .booking-secure-actions .dropdown-toggle .icon {
        font-size: 1.05rem;
    }

    .booking-calendar-dropdown {
        position: relative;
        --booking-calendar-accent: #439982;
        --booking-calendar-accent-soft: rgba(120, 194, 173, 0.18);
        --booking-calendar-accent-outline: rgba(120, 194, 173, 0.35);
    }

    .booking-calendar-dropdown .dropdown-toggle::after {
        margin-left: 0.5rem;
    }

    .booking-calendar-dropdown .dropdown-menu {
        position: absolute;
        top: calc(100% + 0.5rem);
        left: 0;
        width: 100%;
        min-width: 100%;
        padding: 0.5rem 0;
        margin: 0;
        display: none;
        background-color: #ffffff;
        border: 1px solid rgba(31, 41, 51, 0.08);
        border-radius: 0.75rem;
        box-shadow: 0 18px 36px rgba(31, 41, 51, 0.12);
        z-index: 15;
        max-height: 320px;
        overflow-y: auto;
    }

    .booking-calendar-dropdown .dropdown-menu.show {
        display: block;
    }

    .booking-calendar-dropdown .dropdown-item {
        font-size: 1rem;
        padding: 0.45rem 1rem;
        text-transform: none;
        color: #1f2933;
        transition: background-color 150ms ease, color 150ms ease, box-shadow 150ms ease;
    }

    .booking-calendar-dropdown .dropdown-item:visited {
        color: #1f2933;
    }

    .booking-calendar-dropdown .dropdown-item:hover,
    .booking-calendar-dropdown .dropdown-item:focus-visible {
        background-color: var(--booking-calendar-accent-soft);
        color: #1f2933;
    }

    .booking-calendar-dropdown .dropdown-item:focus {
        outline: none;
    }

    .booking-calendar-dropdown .dropdown-item:focus-visible {
        box-shadow: inset 0 0 0 2px var(--booking-calendar-accent-outline);
    }

    .booking-calendar-dropdown .dropdown-item:active {
        background-color: var(--booking-calendar-accent) !important;
        color: #ffffff !important;
    }

    .booking-calendar-dropdown .dropdown-item.active {
        background-color: transparent !important;
        color: #1f2933 !important;
    }

    .booking-utility-links {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .booking-utility-links--mobile {
        width: 100%;
    }

    .booking-utilities {
        display: none;
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
        min-height: 48px;
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

    .booking-calendar-hint {
        margin: 0;
        font-size: 0.8125rem;
        line-height: 1.4;
        color: #6c7785;
        text-align: left;
    }

    .btn-ghost .icon {
        font-size: 1rem;
        color: inherit;
    }

    .btn-ghost .label {
        text-transform: none;
    }

    .booking-change-card {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .booking-change-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        min-height: 48px;
        width: 100%;
        font-weight: 600;
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

    @media (max-width: 767.98px) {
        .booking-confirmation-banner {
            padding: 0;
            background: none;
            border: none;
            align-items: flex-start;
            text-align: left;
            gap: 0.25rem;
        }

        .booking-banner-text {
            display: none;
        }

        .booking-calendar-dropdown .dropdown-menu {
            width: 100%;
            max-width: calc(100vw - clamp(32px, 12vw, 48px));
            left: 0;
            right: auto;
        }
    }

    @media (max-width: 400px) {
        .booking-confirmation-wrapper {
            padding-inline: 0.75rem;
        }

        .booking-banner-title {
            font-size: 1.25rem;
        }
    }

    @media (max-width: 360px) {
        .btn-ghost {
            justify-content: flex-start;
            text-align: left;
            white-space: normal;
        }

        .btn-ghost .label {
            text-align: left;
        }
    }

    @media (min-width: 600px) {
        .booking-secure-actions {
            flex-direction: row;
            align-items: stretch;
        }

        .booking-secure-actions .booking-calendar-actions {
            flex: 1 1 0;
        }

        .booking-secure-actions .btn,
        .booking-secure-actions .dropdown-toggle {
            width: auto;
            flex: 1 1 0;
            padding-inline: 1.5rem;
        }

        .booking-calendar-dropdown {
            flex: 1 1 0;
        }

        .booking-calendar-dropdown .dropdown-menu {
            min-width: 100%;
        }

        .booking-utility-links {
            gap: 1rem;
        }
    }

    @media (min-width: 768px) {
        .booking-confirmation-wrapper {
            padding-inline: clamp(24px, 5vw, 40px);
        }
    }

    @media (min-width: 1024px) {
        .booking-confirmation-wrapper {
            gap: 2.5rem;
            padding-inline: clamp(32px, 5vw, 48px);
        }

        .booking-confirmation-grid {
            display: grid;
            grid-template-columns: minmax(440px, 560px) minmax(420px, 520px);
            grid-template-areas:
                "banner banner"
                "details secure"
                "change utilities";
            column-gap: clamp(2.5rem, 4vw, 3.5rem);
            row-gap: clamp(1.75rem, 3vw, 2rem);
            align-items: start;
            justify-content: center;
        }

        .booking-confirmation-banner {
            grid-area: banner;
        }

        .booking-column-primary {
            grid-area: details;
            max-width: 560px;
            width: 100%;
        }

        .booking-column-secondary {
            grid-area: secure;
            max-width: 520px;
            width: 100%;
        }

        .booking-change-card {
            grid-area: change;
            max-width: 560px;
            align-self: stretch;
        }

        .booking-utilities {
            grid-area: utilities;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 1rem;
            min-height: 48px;
            width: 100%;
            max-width: 520px;
        }

        .booking-utilities .booking-utility-links {
            flex-wrap: nowrap;
            gap: 1rem;
            justify-content: flex-start;
            width: 100%;
        }

        .booking-secure-actions {
            flex-direction: column;
        }

        .booking-secure-actions .btn,
        .booking-secure-actions .dropdown-toggle {
            width: 100%;
            flex: none;
        }

        .booking-utility-links--mobile {
            display: none;
        }
    }
</style>
<?php end_section('styles'); ?>

<?php section('content'); ?>

<?php
$appointment_summary = vars('appointment_summary') ?? [];
$calendar_links = vars('calendar_links') ?? [];
$available_calendar_links = array_filter($calendar_links);
$show_calendar_dropdown = !vars('is_past_event') && !empty($available_calendar_links);
$manage_url = vars('manage_url');
$is_manageable = vars('is_manageable');
$appointment_registered_short = lang('appointment_registered_short') ?: lang('appointment_registered');
$appointment_registered_message = lang('appointment_registered') ?: 'Ihr Termin ist erfolgreich registriert worden.';
$manage_appointment_cta = lang('manage_appointment_cta') ?: 'Manage appointment';
$manage_appointment_cta_locked = lang('manage_appointment_cta_locked') ?: lang('appointment_locked');
$manage_link_hint =
    lang('manage_link_hint') ?:
    'Important: Without this link you will not be able to change or cancel the appointment later.';
$manage_link_locked_hint =
    lang('manage_link_locked_hint') ?:
    (lang('appointment_locked_message') ?:
    'This appointment can no longer be changed online.');
$copy_link_button = lang('copy_link_button') ?: 'Copy link';
$share_link_button = lang('share_link_button') ?: 'Share';
$add_to_calendar_grouped = lang('add_to_calendar_grouped') ?: 'Add to calendar';
$add_to_calendar_hint = lang('add_to_calendar_hint') ?: 'Der Verwaltungslink wird im Kalendereintrag gespeichert.';
$book_another_link = lang('book_another_appointment_link') ?: lang('go_to_booking_page');
?>

<div class="booking-confirmation-wrapper">
    <div class="booking-confirmation-grid">
        <div class="booking-confirmation-banner frame-content">
            <h4 class="booking-banner-title mb-1" lang="de"><?= $appointment_registered_short ?></h4>
            <p class="booking-banner-text mb-0" lang="de"><?= $appointment_registered_message ?></p>
        </div>

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

        </div>

        <div class="booking-column booking-column-secondary">
            <div class="booking-manage-card frame-content">
                <p class="booking-manage-hint">
                    <?= $is_manageable ? $manage_link_hint : $manage_link_locked_hint ?>
                </p>

                <div class="booking-secure-actions">
                    <button type="button"
                            class="btn button-next btn-dark copy-button"
                            data-copy-target="<?= htmlspecialchars($manage_url, ENT_QUOTES, 'UTF-8') ?>">
                        <i class="fas fa-link icon" aria-hidden="true"></i>
                        <span class="label"><?= $copy_link_button ?></span>
                    </button>

                    <?php if ($show_calendar_dropdown): ?>
                        <div class="booking-calendar-actions">
                            <div class="dropdown booking-calendar-dropdown">
                                <button class="btn btn-outline-primary dropdown-toggle booking-calendar-toggle"
                                        type="button"
                                        id="calendarLinksDropdown"
                                        data-calendar-dropdown-toggle
                                        aria-expanded="false"
                                        aria-controls="calendarLinksDropdownMenu">
                                    <i class="fas fa-calendar-day icon" aria-hidden="true"></i>
                                    <span class="label"><?= $add_to_calendar_grouped ?></span>
                                </button>
                                <ul class="dropdown-menu"
                                    id="calendarLinksDropdownMenu"
                                    aria-labelledby="calendarLinksDropdown"
                                    role="menu"
                                    data-calendar-dropdown-menu
                                    hidden>
                                    <?php if (!empty($calendar_links['google'])): ?>
                                        <li role="presentation">
                                            <a class="dropdown-item"
                                               href="<?= htmlspecialchars(
                                                   $calendar_links['google'],
                                                   ENT_QUOTES,
                                                   'UTF-8',
                                               ) ?>"
                                               target="_blank" rel="noopener"
                                               role="menuitem">
                                                <?= lang('add_to_google_calendar') ?>
                                            </a>
                                        </li>
                                    <?php endif; ?>

                                    <?php if (!empty($calendar_links['outlook'])): ?>
                                        <li role="presentation">
                                            <a class="dropdown-item"
                                               href="<?= htmlspecialchars(
                                                   $calendar_links['outlook'],
                                                   ENT_QUOTES,
                                                   'UTF-8',
                                               ) ?>"
                                               target="_blank" rel="noopener"
                                               role="menuitem">
                                                <?= lang('add_to_outlook_calendar') ?>
                                            </a>
                                        </li>
                                    <?php endif; ?>

                                    <?php if (!empty($calendar_links['ics'])): ?>
                                        <li role="presentation">
                                            <a class="dropdown-item"
                                               href="<?= htmlspecialchars(
                                                   $calendar_links['ics'],
                                                   ENT_QUOTES,
                                                   'UTF-8',
                                               ) ?>"
                                               role="menuitem">
                                                <?= lang('add_to_apple_calendar') ?>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            <p class="booking-calendar-hint">
                                <?= htmlspecialchars($add_to_calendar_hint, ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="booking-utility-links booking-utility-links--mobile">
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

        <div class="booking-utilities">
            <div class="booking-utility-links">
                <button type="button"
                        class="btn btn-ghost share-button"
                        data-share-url="<?= htmlspecialchars($manage_url, ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fas fa-share-alt icon" aria-hidden="true"></i>
                    <span class="label"><?= $share_link_button ?></span>
                </button>
            </div>
        </div>

        <div class="booking-change-card frame-content">
            <?php if ($is_manageable): ?>
                <a href="<?= htmlspecialchars($manage_url, ENT_QUOTES, 'UTF-8') ?>"
                   class="btn btn-outline-secondary booking-change-button"
                   rel="noopener">
                    <i class="fas fa-pen icon" aria-hidden="true"></i>
                    <span><?= $manage_appointment_cta ?></span>
                </a>
            <?php else: ?>
                <span class="btn btn-outline-secondary booking-change-button disabled" role="button" aria-disabled="true" tabindex="-1">
                    <?= $manage_appointment_cta_locked ?>
                </span>
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
        const dropdownToggles = document.querySelectorAll('[data-calendar-dropdown-toggle]');
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

        const dropdownStates = [];

        const focusFirstItem = (menu) => {
            const firstFocusable = menu.querySelector('[role="menuitem"], a, button, [tabindex]:not([tabindex="-1"])');

            if (firstFocusable && typeof firstFocusable.focus === 'function') {
                firstFocusable.focus();
            }
        };

        const closeDropdown = (state) => {
            const { toggle, menu } = state;

            if (!menu) {
                return;
            }

            menu.classList.remove('show');
            menu.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        };

        const openDropdown = (state, options = {}) => {
            const { toggle, menu } = state;
            const { focusFirst = false } = options;

            if (!menu) {
                return;
            }

            dropdownStates.forEach((otherState) => {
                if (otherState !== state) {
                    closeDropdown(otherState);
                }
            });

            menu.hidden = false;
            menu.classList.add('show');
            toggle.setAttribute('aria-expanded', 'true');
            if (focusFirst) {
                focusFirstItem(menu);
            }
        };

        dropdownToggles.forEach((toggle) => {
            const menuId = toggle.getAttribute('aria-controls');
            const menu = menuId ? document.getElementById(menuId) : null;

            if (!menu) {
                return;
            }

            const state = { toggle, menu };
            dropdownStates.push(state);

            menu.hidden = true;

            toggle.addEventListener('click', (event) => {
                event.preventDefault();
                const isOpen = !menu.hidden;

                if (isOpen) {
                    closeDropdown(state);
                } else {
                    openDropdown(state);
                }
            });

            toggle.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openDropdown(state, { focusFirst: true });
                } else if (event.key === 'Escape') {
                    closeDropdown(state);
                }
            });

            menu.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeDropdown(state);
                    toggle.focus();
                }
            });
        });

        if (dropdownStates.length > 0) {
            document.addEventListener('click', (event) => {
                const isInside = dropdownStates.some(({ toggle, menu }) => toggle.contains(event.target) || menu.contains(event.target));

                if (!isInside) {
                    dropdownStates.forEach((state) => closeDropdown(state));
                }
            });

            document.addEventListener('focusin', (event) => {
                if (!event.target) {
                    return;
                }

                const isInside = dropdownStates.some(({ toggle, menu }) => toggle.contains(event.target) || menu.contains(event.target));

                if (!isInside) {
                    dropdownStates.forEach((state) => closeDropdown(state));
                }
            });
        }
    })();
</script>

<?php component('google_analytics_script', ['google_analytics_code' => vars('google_analytics_code')]); ?>
<?php component('matomo_analytics_script', [
    'matomo_analytics_url' => vars('matomo_analytics_url'),
    'matomo_analytics_site_id' => vars('matomo_analytics_site_id'),
]); ?>

<?php end_section('scripts'); ?>
