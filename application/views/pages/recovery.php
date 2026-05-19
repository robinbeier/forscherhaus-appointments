<?php extend('layouts/account_layout'); ?>

<?php section('content'); ?>

<h2><?= lang('forgot_your_password') ?></h2>

<hr>

<div class="alert alert-info mb-5">
    <?= lang('password_recovery_contact_robin') ?>
</div>

<a href="<?= site_url('login') ?>" class="user-login">
    <?= lang('go_to_login') ?>
</a>

<?php end_section('content'); ?>
