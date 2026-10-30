<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'judgelist']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$sid     = (int) $slam['id'];
$endMode = $judging['timing'] === 'end_of_slam';
$modeTxt = $endMode ? 'Entered by you at the end of the slam' : 'Live on their phones, ' . ($judging['mode'] === 'consolidated' ? 'one overall mark' : 'by criteria');
?>
<div class="row row-cards mb-3">
    <?php foreach ([
        ['Judges', $counts['judges'], 'person.judge', 'purple', 'registered'],
        ['Checked in', $counts['checked_in'], 'nav.checkins', 'green', $endMode ? 'optional: they mark on paper' : 'inside right now'],
        [$endMode ? 'Marks entered' : 'Reviews submitted', $endMode ? $progress['entered'] : (int) \Modules\Core\Libraries\Db::value("SELECT COUNT(*) FROM reviews r JOIN attendees a ON a.id = r.attendee_id WHERE a.slam_id = ? AND a.user_type = 'judge'", [$sid]), 'status.ok', 'orange', $endMode ? 'of ' . $progress['expected'] . ' (judges × performances)' : $counts['opened'] . ' performance(s) ran'],
    ] as [$label, $n, $ic, $colour, $sub]): ?>
        <div class="col-6 col-xl-3">
            <div class="card h-100"><div class="card-body d-flex align-items-center gap-3 py-3">
                <span class="avatar avatar-md bg-<?= $colour ?>-lt flex-shrink-0"><?= icon($ic) ?></span>
                <div class="min-w-0"><div class="h1 m-0 lh-1"><?= (int) $n ?></div><div class="mt-1"><?= esc($label) ?></div><div class="small text-secondary"><?= esc($sub) ?></div></div>
            </div></div>
        </div>
    <?php endforeach ?>
    <div class="col-6 col-xl-3">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-secondary small">How judges mark</div>
            <div class="fw-bold"><?= esc($modeTxt) ?></div>
            <a href="<?= site_url("admin/slams/{$sid}/mark-config") ?>" class="small"><?= icon('nav.settings', 'icon-inline me-1') ?>Change</a>
        </div></div>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="<?= site_url("admin/slams/{$sid}/attendees/new?type=judge&back=judges") ?>" class="btn btn-primary"><?= icon('action.add') ?>Add judge</a>
    <?php if ($endMode): ?><a href="<?= site_url("admin/slams/{$sid}/judge-marks") ?>" class="btn btn-warning"><?= icon('person.judge') ?>Enter judges' marks</a><?php endif ?>
    <a href="<?= site_url("admin/slams/{$sid}/questions") ?>" class="btn"><?= icon('nav.questions') ?>Judge criteria</a>
    <a href="<?= site_url("admin/slams/{$sid}/attendees/import") ?>" class="btn"><?= icon('action.upload') ?>Import CSV</a>
    <a href="<?= site_url("admin/qr?slam={$sid}") ?>" class="btn"><?= icon('action.print') ?>Badges &amp; QR</a>
    <a href="#" id="judge-export" class="btn"><?= icon('action.export') ?>Export view (CSV)</a>
</div>
<?php if ($endMode && $marksLocked !== null): ?><div class="alert alert-info"><?= icon('status.lock', 'me-1') ?><?= esc($marksLocked) ?></div><?php endif ?>

<div class="card">
    <div class="card-header">
        <form id="judge-filters" class="row g-2 w-100">
            <input type="hidden" name="user_type" value="judge">
            <div class="col-6 col-md-3"><select name="checked_in" class="form-select" aria-label="Check-in"><option value="">Any check-in state</option><option value="1">Checked in</option><option value="0">Not checked in</option></select></div>
            <div class="col-6 col-md-3"><select name="is_active" class="form-select" aria-label="Active"><option value="">Active &amp; inactive</option><option value="1">Active</option><option value="0">Inactive</option></select></div>
        </form>
    </div>
    <div class="card-body">
        <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
            'endpoint' => 'attendees', 'filters' => ['slam_id' => $sid], 'filterForm' => '#judge-filters', 'exportLink' => '#judge-export',
            'order' => [[1, 'asc']], 'empty' => 'No judges yet. Add them one by one or import a CSV with type "judge".', 'refresh' => 15000,
            'columns' => [
                ['data' => 'id', 'title' => '#', 'priority' => 50],
                ['data' => 'name', 'title' => 'Name', 'render' => 'ml', 'priority' => 1],
                ['data' => 'badge_id', 'title' => 'ID', 'priority' => 2],
                ['data' => 'checked_in', 'title' => 'Check-in', 'render' => 'checkedin', 'priority' => 3],
                ['data' => 'reviews', 'title' => $endMode ? 'Marks entered' : 'Reviews', 'priority' => 4],
                ['data' => 'mobile', 'title' => 'Mobile', 'orderable' => false, 'priority' => 6],
                ['data' => 'email', 'title' => 'Email', 'orderable' => false, 'priority' => 8],
                ['data' => 'badge_print_count', 'title' => 'Printed', 'priority' => 9],
                ['data' => 'is_active', 'title' => 'Status', 'render' => 'active', 'priority' => 7],
            ],
            'actions' => array_values(array_filter([
                $endMode ? ['label' => "Enter this judge's marks", 'icon' => 'person.judge', 'href' => "/admin/slams/{$sid}/judge-marks?judge={id}"] : null,
                ['label' => 'Edit / reissue ID', 'icon' => 'action.edit', 'href' => '/admin/attendees/{id}/edit?back=judges'],
                ['label' => 'Timeline', 'icon' => 'nav.log', 'href' => '/admin/activity-log?target_type=attendee&target_id={id}'],
                ['label' => 'Late check-in (after Gate Close)', 'icon' => 'nav.checkins', 'post' => '/api/checkin/late-override', 'body' => ['attendee_id' => '{id}'], 'reason' => true, 'when' => '!checked_in'],
                ['label' => 'Force check-out', 'icon' => 'action.logout', 'post' => '/api/force-checkout/{id}', 'reason' => true, 'danger' => true, 'when' => 'checked_in'],
            ])),
        ]), 'attr') ?>'></table>
    </div>
</div>
<?= $this->endSection() ?>
