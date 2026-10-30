<?php $this->setVar('narrow', true); $this->setVar('entry', 'frontend/entries/checkin.js'); $this->setVar('title', ($kind === 'admin' ? 'Organiser' : 'Volunteer') . ' sign-in'); ?>
<?= $this->extend('Modules\Core\Views\layouts\public') ?>
<?= $this->section('content') ?>
<div class="text-center mb-4">
    <a href="<?= site_url('/') ?>" class="d-inline-block"><?= brand_logo('brand-logo-card') ?></a>
</div>
<form class="card card-md" method="post" action="<?= site_url($kind . '/login') ?>" autocomplete="on" data-once>
    <?= csrf_field() ?>
    <?= honeypot_fields() ?>
    <div class="card-body">
        <h2 class="h2 text-center mb-4"><?= $kind === 'admin' ? 'Organiser sign-in' : 'Volunteer sign-in' ?></h2>
        <div class="mb-3">
            <label class="form-label" for="username">Username</label>
            <input id="username" name="username" class="form-control" autocomplete="username" autocapitalize="none" required value="<?= esc(old('username')) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <input id="password" name="password" type="password" class="form-control" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary w-100" type="submit">Sign in</button>
    </div>
</form>
<p class="text-center small mt-3"><a href="<?= site_url('/') ?>" class="text-secondary">← Back to home</a></p>
<?= $this->endSection() ?>
