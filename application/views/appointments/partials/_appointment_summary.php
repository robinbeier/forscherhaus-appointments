<?php
/**
 * Appointment summary partial.
 *
 * @var array|null $appointment Summary data for the appointment column.
 * @var array|null $customer Summary data for the customer column.
 * @var string|null $wrapper_classes Optional wrapper classes.
 * @var bool|null $show_customer Toggle rendering of the customer column.
 * @var string|null $appointment_details_id DOM id for the appointment column.
 * @var string|null $customer_details_id DOM id for the customer column.
 * @var string|null $appointment_column_classes Optional classes for the appointment column.
 * @var string|null $customer_column_classes Optional classes for the customer column.
 */

$wrapper_classes = $wrapper_classes ?? 'row frame-content m-auto pt-md-4 mb-4';
$appointment_details_id = $appointment_details_id ?? 'appointment-details';
$customer_details_id = $customer_details_id ?? 'customer-details';
$show_customer = $show_customer ?? true;
$appointment_column_classes = $appointment_column_classes ?? 'col-12 col-md-6 text-center text-md-start mb-2 mb-md-0';
$customer_column_classes = $customer_column_classes ?? 'col-12 col-md-6 text-center text-md-end';
$secondary_column_content = $secondary_column_content ?? null;

$should_render_customer_details = $show_customer && !empty($customer);
$should_render_secondary_content = !empty($secondary_column_content);
$should_render_secondary_column = $show_customer || $should_render_secondary_content;
?>

<div class="<?= htmlspecialchars($wrapper_classes, ENT_QUOTES, 'UTF-8') ?>">
    <div id="<?= htmlspecialchars($appointment_details_id, ENT_QUOTES, 'UTF-8') ?>"
         class="<?= htmlspecialchars($appointment_column_classes, ENT_QUOTES, 'UTF-8') ?>">
        <?php if (!empty($appointment)): ?>
            <div class="summary-details">
                <?php if (!empty($appointment['title'])): ?>
                    <div class="summary-title fw-semibold fs-4 text-primary mb-2" lang="de">
                        <?= htmlspecialchars($appointment['title'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($appointment['subtitle'])): ?>
                    <div class="summary-subtitle fw-bold text-muted">
                        <?= htmlspecialchars($appointment['subtitle'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($appointment['room'])): ?>
                    <div class="summary-item">
                        <i class="fas fa-door-open"></i>
                        <span><?= htmlspecialchars($appointment['room'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($appointment['datetime'])): ?>
                    <div class="summary-item">
                        <i class="fas fa-calendar-day"></i>
                        <span><?= htmlspecialchars($appointment['datetime'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($appointment['duration'])): ?>
                    <div class="summary-item">
                        <i class="fas fa-clock"></i>
                        <span><?= htmlspecialchars($appointment['duration'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($appointment['timezone'])): ?>
                    <div class="summary-item">
                        <i class="fas fa-globe"></i>
                        <span><?= htmlspecialchars($appointment['timezone'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($appointment['price'])): ?>
                    <div class="summary-item">
                        <i class="fas fa-cash-register"></i>
                        <span><?= htmlspecialchars($appointment['price'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($should_render_secondary_column): ?>
        <div id="<?= htmlspecialchars($customer_details_id, ENT_QUOTES, 'UTF-8') ?>"
             class="<?= htmlspecialchars($customer_column_classes, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($should_render_secondary_content): ?>
                <?= $secondary_column_content ?>
            <?php endif; ?>

            <?php if ($should_render_customer_details): ?>
                <div>
                    <?php if (!empty($customer['title'])): ?>
                        <div class="mb-2 fw-semibold fs-5 text-secondary">
                            <?= htmlspecialchars($customer['title'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($customer['items'] ?? [] as $item): ?>
                        <div class="mb-2">
                            <?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
