<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?>
<div class="page-header"><div class="container-xl"><div class="row g-2 align-items-center">
    <div class="col"><h2 class="page-title">Dashboard</h2></div>
    <div class="col-auto"><a href="<?= site_url('admin/slams/new') ?>" class="btn btn-primary"><?= icon('action.add') ?>New slam</a></div>
</div></div></div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php if ($slams === []): ?>
    <div class="empty">
        <div class="empty-icon"><?= icon('nav.slams', 'icon-xl') ?></div>
        <p class="empty-title">No slams yet</p>
        <p class="empty-subtitle text-secondary">Create the 4 regional slams and the final. Each one has its own times, schedule, questions and attendees.</p>
        <div class="empty-action"><a href="<?= site_url('admin/slams/new') ?>" class="btn btn-primary"><?= icon('action.add') ?>Create a slam</a></div>
    </div>
<?php endif ?>
<div class="row row-cards">
    <?php foreach ($slams as $s): ?>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-start mb-2">
                        <div class="me-auto">
                            <div class="text-secondary small"><?= esc(ucfirst($s['type'])) ?> · <?= esc(local_time($s['opens_at'], 'D, d M Y')) ?></div>
                            <h3 class="card-title mb-0"><a href="<?= site_url('admin/slams/' . $s['id']) ?>" class="text-reset"><?= esc($s['name']) ?></a></h3>
                            <div class="text-secondary small"><?= icon('place.venue', 'icon-inline') ?> <?= esc($s['venue_name']) ?><?= $s['city'] ? ', ' . esc($s['city']) : '' ?></div>
                        </div>
                    </div>
                    <div class="mb-3"><?= status_badge('phase', $s['status']) ?>
                        <?php if ($s['auto_mode'] === 'manual'): ?><span class="badge bg-orange-lt">MANUAL</span><?php elseif ((int) $s['hold_next_step']): ?><span class="badge bg-orange-lt">HELD</span><?php else: ?><span class="badge bg-blue-lt">AUTO</span><?php endif ?>
                    </div>
                    <div class="row text-center g-0">
                        <div class="col"><div class="h3 m-0"><?= (int) $s['counts']['performances'] ?></div><div class="small text-secondary">Performances</div></div>
                        <div class="col"><div class="h3 m-0"><?= (int) $s['counts']['attendees'] ?></div><div class="small text-secondary">Registered</div></div>
                        <div class="col"><div class="h3 m-0"><?= (int) $s['counts']['checked_in'] ?></div><div class="small text-secondary">Checked in</div></div>
                    </div>
                </div>
                <div class="card-footer d-flex gap-2 flex-wrap">
                    <a class="btn btn-primary flex-fill" href="<?= site_url('admin/slams/' . $s['id'] . '/status') ?>"><?= icon('nav.status') ?>Run the slam</a>
                    <a class="btn flex-fill" href="<?= site_url('admin/slams/' . $s['id']) ?>">Open</a>
                </div>
            </div>
        </div>
    <?php endforeach ?>
</div>
<?= $this->endSection() ?>
