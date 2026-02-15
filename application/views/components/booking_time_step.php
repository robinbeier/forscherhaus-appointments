<?php
/**
 * Local variables.
 *
 * @var array $grouped_timezones
 */

$no_slot_fallback_enabled = vars('no_slot_fallback_enabled', '1') === '1'; ?>

<div id="wizard-frame-2" class="wizard-frame" style="display:none;">
    <div class="frame-container">

        <h2 class="frame-title"><?= lang('appointment_date_and_time') ?></h2>

        <div class="row frame-content">
            <div class="col-12 col-md-6">
                <div id="select-date"></div>

                <?php slot('after_select_date'); ?>
            </div>

            <div class="col-12 col-md-6">
                <div id="select-time">
                    <div class="mb-3">
                        <label for="select-timezone" class="form-label">
                            <?= lang('timezone') ?>
                        </label>
                        <?php component('timezone_dropdown', [
                            'attributes' => 'id="select-timezone" class="form-select" value="UTC"',
                            'grouped_timezones' => $grouped_timezones,
                        ]); ?>
                    </div>

                    <?php slot('after_select_timezone'); ?>


                    <div id="available-hours"></div>

                    <?php if ($no_slot_fallback_enabled): ?>
                        <div id="no-slot-fallback" class="no-slot-fallback mt-3">
                            <button
                                type="button"
                                id="no-slot-fallback-trigger"
                                class="btn btn-outline-secondary w-100"
                                data-entry-point="inline"
                                aria-expanded="false"
                                aria-controls="no-slot-fallback-panel"
                            >
                                <?= lang('no_slot_fallback_trigger') ?>
                            </button>

                            <div id="no-slot-fallback-panel" class="alert alert-light border mt-3 mb-0" hidden>
                                <h5 class="h6 mb-2"><?= lang('no_slot_fallback_title') ?></h5>
                                <p class="mb-2"><?= lang('no_slot_fallback_body') ?></p>

                                <button type="button" id="no-slot-fallback-close" class="btn btn-link p-0">
                                    <?= lang('close') ?>
                                </button>
                            </div>

                            <noscript>
                                <div class="alert alert-light border mt-3 mb-0">
                                    <h5 class="h6 mb-2"><?= lang('no_slot_fallback_title') ?></h5>
                                    <p class="mb-0"><?= lang('no_slot_fallback_body') ?></p>
                                </div>
                            </noscript>
                        </div>
                    <?php endif; ?>

                    <?php slot('after_available_hours'); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="command-buttons">
        <button type="button" id="button-back-2" class="btn button-back btn-outline-secondary"
                data-step_index="2">
            <i class="fas fa-chevron-left me-2"></i>
            <?= lang('back') ?>
        </button>
        <button type="button" id="button-next-2" class="btn button-next btn-dark"
                data-step_index="2">
            <?= lang('next') ?>
            <i class="fas fa-chevron-right ms-2"></i>
        </button>
    </div>
</div>
