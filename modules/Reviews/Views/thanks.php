<?php $this->setVar('narrow', true); $this->setVar('entry', 'frontend/entries/checkin.js'); $this->setVar('title', 'Thank you · Science Slam'); ?>
<?= $this->extend('Modules\Core\Views\layouts\public') ?>
<?= $this->section('content') ?>
<div class="card card-md text-center"><div class="card-body py-5">
    <?= icon('phase.marks_calculated', 'icon-xl text-green mb-3') ?>
    <h2 class="h1"><?= esc($message) ?></h2>
    <p class="fs-3 mb-1">The slam is closed.</p>
    <p class="text-secondary">The results will be announced soon.</p>
    <a class="btn" href="<?= site_url('slams') ?>">Schedules</a>
</div></div>
<?= $this->endSection() ?>
