<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('content') ?>
<?php /** @var array $a @var array $slam @var string $svg @var string $url */ ?>
<div class="card mx-auto qr-one" style="max-width: 28rem">
    <div class="card-body text-center">
        <div class="text-secondary small text-uppercase fw-bold mb-1"><?= esc($slam['name']) ?></div>
        <h2 class="h1 ml-text mb-1"><?= esc($a['name']) ?></h2>
        <div class="mb-3"><?= status_badge('user_type', $a['user_type']) ?></div>
        <div class="mx-auto" style="max-width: 20rem" role="img" aria-label="QR code for <?= esc($a['badge_id'], 'attr') ?>"><?= $svg ?></div>
        <div class="display-6 fw-bold mt-2" style="letter-spacing:.15em"><?= esc($a['badge_id']) ?></div>
        <div class="small text-secondary text-break mt-1"><?= esc($url) ?></div>
        <div class="btn-list justify-content-center mt-3 d-print-none">
            <a class="btn btn-primary" href="<?= site_url('admin/qr/' . $a['id'] . '.png') ?>"><?= icon('action.download') ?>Download PNG</a>
            <button class="btn" type="button" data-print><?= icon('action.print') ?>Print</button>
            <a class="btn btn-link" href="<?= site_url('admin/attendees/' . $a['id'] . '/edit') ?>">Edit / reissue ID</a>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
