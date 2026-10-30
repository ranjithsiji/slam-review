<?php $this->setVar('title', 'Science Slam schedules'); $this->setVar('indexable', true); ?>
<?php $this->setVar('ownToggle', true); ?>
<?= $this->extend('Modules\Core\Views\layouts\public') ?>
<?= $this->section('top') ?>
<header class="navbar navbar-expand"><div class="container-xl">
    <a href="<?= site_url('/') ?>" class="navbar-brand"><?= brand_logo('brand-logo-nav') ?></a>
    <div class="ms-auto"><?= theme_toggle() ?></div>
</div></header>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<h1 class="mb-3">Slams</h1>
<?php if ($slams === []): ?><div class="empty"><p class="empty-title">No schedules are published yet.</p></div><?php endif ?>
<div class="row row-cards">
    <?php foreach ($slams as $s): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <a class="card card-link card-link-pop h-100" href="<?= site_url('slams/' . $s['slug']) ?>">
                <div class="card-body">
                    <div class="mb-2"><?= status_badge('public', $s['badge']) ?></div>
                    <h3 class="card-title mb-1"><?= esc($s['name']) ?></h3>
                    <div class="text-secondary"><?= icon('nav.slams', 'icon-inline me-1') ?><?= esc(local_time($s['opens_at'], 'l, d M Y')) ?></div>
                    <div class="text-secondary"><?= icon('place.venue', 'icon-inline me-1') ?><?= esc($s['venue_name']) ?><?= $s['city'] ? ', ' . esc($s['city']) : '' ?></div>
                </div>
            </a>
        </div>
    <?php endforeach ?>
</div>
<?= $this->endSection() ?>
