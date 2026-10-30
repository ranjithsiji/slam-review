<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'reviewers']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $sid = (int) $slam['id']; ?>
<div class="row row-cards mb-3">
    <?php foreach ([
        ['Reviewers', $counts['users'], 'person.user', 'blue', 'audience users registered'],
        ['Checked in', $counts['checked_in'], 'nav.checkins', 'green', 'inside right now'],
        ['Reviewed everything', $counts['complete'], 'status.ok', 'orange', $counts['opened'] . ' performance(s) ran'],
        ['Representatives', $counts['representatives'], 'nav.representatives', 'purple', 'of ' . $repsWanted . ' wanted'],
    ] as [$label, $n, $ic, $colour, $sub]): ?>
        <div class="col-6 col-xl-3">
            <div class="card h-100"><div class="card-body d-flex align-items-center gap-3 py-3">
                <span class="avatar avatar-md bg-<?= $colour ?>-lt flex-shrink-0"><?= icon($ic) ?></span>
                <div class="min-w-0"><div class="h1 m-0 lh-1"><?= (int) $n ?></div><div class="mt-1"><?= esc($label) ?></div><div class="small text-secondary"><?= esc($sub) ?></div></div>
            </div></div>
        </div>
    <?php endforeach ?>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="<?= site_url("admin/slams/{$sid}/attendees/new?type=user&back=reviewers") ?>" class="btn btn-primary"><?= icon('action.add') ?>Add reviewer</a>
    <a href="<?= site_url("admin/slams/{$sid}/attendees/import") ?>" class="btn"><?= icon('action.upload') ?>Import CSV</a>
    <a href="<?= site_url("admin/slams/{$sid}/representatives") ?>" class="btn"><?= icon('nav.representatives') ?>Draw audience representatives</a>
    <a href="<?= site_url("admin/qr?slam={$sid}") ?>" class="btn"><?= icon('action.print') ?>Badges &amp; QR</a>
    <a href="#" id="rev-export" class="btn"><?= icon('action.export') ?>Export view (CSV)</a>
</div>
<?php if ($repsLocked !== null): ?><div class="alert alert-info"><?= icon('status.lock', 'me-1') ?>Representatives: <?= esc($repsLocked) ?></div><?php endif ?>

<div class="card">
    <div class="card-header">
        <form id="rev-filters" class="row g-2 w-100">
            <input type="hidden" name="user_type" value="user">
            <div class="col-6 col-md-3"><select name="checked_in" class="form-select" aria-label="Check-in"><option value="">Any check-in state</option><option value="1">Checked in</option><option value="0">Not checked in</option></select></div>
            <div class="col-6 col-md-3"><select name="complete" class="form-select" aria-label="Reviewed every performance"><option value="">Reviewed any number</option><option value="1">Reviewed every performance</option><option value="0">Missed some</option></select></div>
            <div class="col-6 col-md-3"><select name="representative" class="form-select" aria-label="Representative"><option value="">Representative or not</option><option value="1">Representatives</option><option value="0">General reviewers</option></select></div>
            <div class="col-6 col-md-3"><select name="is_active" class="form-select" aria-label="Active"><option value="">Active &amp; inactive</option><option value="1">Active</option><option value="0">Inactive</option></select></div>
        </form>
    </div>
    <div class="card-body">
        <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
            'endpoint' => 'attendees', 'filters' => ['slam_id' => $sid], 'filterForm' => '#rev-filters', 'exportLink' => '#rev-export',
            'order' => [[1, 'asc']], 'empty' => 'No reviewers yet. Import a CSV or add people one by one.', 'refresh' => 15000,
            'columns' => [
                ['data' => 'id', 'title' => '#', 'priority' => 50],
                ['data' => 'name', 'title' => 'Name', 'render' => 'ml', 'priority' => 1],
                ['data' => 'badge_id', 'title' => 'ID', 'priority' => 2],
                ['data' => 'checked_in', 'title' => 'Check-in', 'render' => 'checkedin', 'priority' => 3],
                ['data' => 'reviews', 'title' => 'Reviews', 'priority' => 4],
                ['data' => 'complete', 'title' => 'Reviewed all', 'render' => 'bool', 'priority' => 6],
                ['data' => 'is_representative', 'title' => 'Representative', 'render' => 'bool', 'priority' => 5],
                ['data' => 'mobile', 'title' => 'Mobile', 'orderable' => false, 'priority' => 7],
                ['data' => 'email', 'title' => 'Email', 'orderable' => false, 'priority' => 9],
                ['data' => 'is_active', 'title' => 'Status', 'render' => 'active', 'priority' => 8],
            ],
            'actions' => array_values(array_filter([
                ['label' => 'Edit / reissue ID', 'icon' => 'action.edit', 'href' => '/admin/attendees/{id}/edit?back=reviewers'],
                ['label' => 'Timeline', 'icon' => 'nav.log', 'href' => '/admin/activity-log?target_type=attendee&target_id={id}'],
                $repsLocked === null ? ['label' => 'Make representative', 'icon' => 'nav.representatives', 'post' => "/api/slams/{$sid}/representatives/{id}/add", 'when' => 'can_make_rep', 'reloadPage' => true, 'success' => 'Added to the representatives.'] : null,
                $repsLocked === null ? ['label' => 'Remove from representatives', 'icon' => 'action.delete', 'post' => "/api/slams/{$sid}/representatives/{id}/remove", 'when' => 'is_representative', 'reason' => true, 'danger' => true, 'reloadPage' => true, 'success' => 'Removed from the representatives.'] : null,
                ['label' => 'Late check-in (after Gate Close)', 'icon' => 'nav.checkins', 'post' => '/api/checkin/late-override', 'body' => ['attendee_id' => '{id}'], 'reason' => true, 'when' => '!checked_in'],
                ['label' => 'Force check-out', 'icon' => 'action.logout', 'post' => '/api/force-checkout/{id}', 'reason' => true, 'danger' => true, 'when' => 'checked_in'],
            ])),
        ]), 'attr') ?>'></table>
    </div>
</div>
<?= $this->endSection() ?>
