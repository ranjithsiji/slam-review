<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'overview']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php if ($readiness !== [] && in_array($slam['status'], ['draft', 'scheduled'], true)): ?>
    <div class="alert alert-warning">
        <div class="d-flex gap-2"><?= icon('status.warning') ?><div>
            <h4 class="alert-title">Not ready to open yet</h4>
            <ul class="mb-0"><?php foreach ($readiness as $m): ?><li><?= esc($m) ?></li><?php endforeach ?></ul>
        </div></div>
    </div>
<?php endif ?>
<!-- Row 1: the numbers, equal-width cards across the full width. -->
<div class="row row-cards mb-3">
    <?php foreach ([
        ['Users', $counts['users'], 'person.user', 'blue'], ['Judges', $counts['judges'], 'person.judge', 'purple'],
        ['Checked in', $counts['checked_in'], 'nav.checkins', 'green'], ['Performances', $counts['performances'], 'nav.presenters', 'orange'],
        ['Reviews', $counts['reviews'], 'nav.questions', 'azure'],
    ] as [$label, $n, $ic, $colour]): ?>
        <div class="col-6 col-md-4 col-xl">
            <div class="card h-100"><div class="card-body d-flex align-items-center gap-3 py-3">
                <span class="avatar avatar-md bg-<?= $colour ?>-lt flex-shrink-0"><?= icon($ic) ?></span>
                <div class="min-w-0"><div class="h1 m-0 lh-1"><?= (int) $n ?></div><div class="text-secondary mt-1"><?= esc($label) ?></div></div>
            </div></div>
        </div>
    <?php endforeach ?>
</div>
<!-- Row 2: details. -->
<div class="row row-cards">
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header"><h3 class="card-title">Times (<?= esc(tz_label()) ?>)</h3>
                <div class="card-actions"><a href="<?= site_url('admin/slams/' . $slam['id'] . '/edit') ?>" class="btn btn-sm"><?= icon('action.edit') ?>Edit</a></div></div>
            <div class="list-group list-group-flush">
                <?php foreach ([
                    ['Slam open', 'opens_at'], ['Gates open (check-in)', 'checkin_opens_at'], ['Gate close', 'checkin_closes_at'],
                    ['Reviews start', 'reviews_start_at'], ['Slam close (hard stop)', 'closes_at'], ['Event ends (automatic)', 'event_ends_at'],
                ] as [$label, $f]): ?>
                    <div class="list-group-item d-flex"><span class="text-secondary me-auto"><?= esc($label) ?></span>
                        <strong><?= $slam[$f] ? esc(local_time($slam[$f], 'D d M, H:i')) : '<span class="text-secondary fw-normal">' . ($f === 'event_ends_at' ? 'admin ends the event' : 'by hand') . '</span>' ?></strong></div>
                <?php endforeach ?>
                <div class="list-group-item small text-secondary">
                    Review window <?= (int) $slam['default_review_minutes'] ?> min · changeover <?= (int) $slam['changeover_minutes'] ?> min ·
                    edit window <?= (int) $slam['edit_window_minutes'] ?: 'skipped' ?><?= (int) $slam['edit_window_minutes'] ? ' min' : '' ?> ·
                    grace <?= (int) $slam['close_grace_minutes'] ?> min · tea <?= (int) $slam['default_tea_break_minutes'] ?> min · lunch <?= (int) $slam['default_lunch_break_minutes'] ?> min
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Public schedule</h3></div>
            <div class="card-body">
                <p class="mb-2"><?= (int) $slam['schedule_is_public'] ? status_badge('public', 'live', 'Published') : '<span class="badge bg-secondary-lt">Not published</span>' ?></p>
                <div class="input-group mb-2">
                    <input class="form-control" readonly value="<?= esc($publicUrl) ?>" aria-label="Public link">
                    <button class="btn" type="button" data-copy="<?= esc($publicUrl, 'attr') ?>"><?= icon('action.copy') ?></button>
                </div>
                <form method="post" action="<?= site_url('admin/slams/' . $slam['id'] . '/publish') ?>" class="d-flex gap-2 flex-wrap"
                      data-confirm="<?= (int) $slam['schedule_is_public'] ? 'Hide the public schedule? The link will return 404.' : 'Publish the running order, presenters and topics to anyone with the link?' ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="public" value="<?= (int) $slam['schedule_is_public'] ? 0 : 1 ?>">
                    <button class="btn <?= (int) $slam['schedule_is_public'] ? 'btn-outline-danger' : 'btn-success' ?>" type="submit"><?= (int) $slam['schedule_is_public'] ? 'Unpublish' : 'Publish schedule' ?></button>
                    <?php if ((int) $slam['schedule_is_public']): ?><a class="btn" href="<?= esc($publicUrl) ?>" target="_blank" rel="noopener"><?= icon('action.open') ?>View</a><?php endif ?>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">Venue &amp; contact</h3></div>
            <div class="card-body">
                <div><?= icon('place.venue', 'me-1') ?><strong><?= esc($slam['venue_name']) ?></strong></div>
                <div class="text-secondary"><?= esc(implode(', ', array_filter([$slam['address_line'], $slam['city'], $slam['district'], $slam['state'], $slam['pincode']]))) ?></div>
                <?php if ($slam['map_url']): ?><a href="<?= esc($slam['map_url']) ?>" target="_blank" rel="noopener noreferrer">Map</a><?php endif ?>
                <?php if ($slam['contact_name']): ?><div class="mt-2"><?= icon('contact.phone', 'me-1') ?><?= esc($slam['contact_name']) ?> <?= esc((string) $slam['contact_phone']) ?> <?= esc((string) $slam['contact_email']) ?></div><?php endif ?>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card"><div class="card-body d-flex flex-wrap gap-2">
            <a class="btn" href="<?= site_url('admin/slams/' . $slam['id'] . '/mark-config') ?>"><?= icon('nav.settings') ?>Mark configuration</a>
            <a class="btn" href="<?= site_url('admin/slams/' . $slam['id'] . '/representatives') ?>"><?= icon('nav.attendees') ?>Audience representatives</a>
            <a class="btn" href="<?= site_url('admin/qr?slam=' . $slam['id']) ?>"><?= icon('nav.qr') ?>QR &amp; badges</a>
            <a class="btn" href="<?= site_url('admin/volunteers/new?slam=' . $slam['id']) ?>"><?= icon('nav.volunteers') ?>Add volunteer</a>
            <a class="btn" href="<?= site_url('admin/activity-log?slam_id=' . $slam['id']) ?>"><?= icon('nav.log') ?>Activity log</a>
            <a class="btn" href="<?= site_url('volunteer?slam=' . $slam['id']) ?>"><?= icon('nav.checkins') ?>Desk view</a>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>
