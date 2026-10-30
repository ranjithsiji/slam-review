<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'attendees']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="<?= site_url('admin/slams/' . $slam['id'] . '/attendees/new') ?>" class="btn btn-primary"><?= icon('action.add') ?>Add attendee</a>
    <a href="<?= site_url('admin/slams/' . $slam['id'] . '/attendees/import') ?>" class="btn"><?= icon('action.upload') ?>Import CSV</a>
    <a href="<?= site_url('admin/qr?slam=' . $slam['id']) ?>" class="btn"><?= icon('action.print') ?>Badges &amp; QR</a>
    <a href="#" id="att-export" class="btn"><?= icon('action.export') ?>Export view (CSV)</a>
</div>
<div class="card">
    <div class="card-header">
        <form id="att-filters" class="row g-2 w-100">
            <div class="col-6 col-md-3"><select name="user_type" class="form-select" aria-label="Type"><option value="">Users &amp; judges</option><option value="user">Users</option><option value="judge">Judges</option></select></div>
            <div class="col-6 col-md-3"><select name="checked_in" class="form-select" aria-label="Check-in"><option value="">Any check-in state</option><option value="1">Checked in</option><option value="0">Not checked in</option></select></div>
            <div class="col-6 col-md-3"><select name="is_active" class="form-select" aria-label="Active"><option value="">Active &amp; inactive</option><option value="1">Active</option><option value="0">Inactive</option></select></div>
            <div class="col-6 col-md-3"><select name="import_batch_id" class="form-select" aria-label="Import batch"><option value="">Any import batch</option>
                <?php foreach ($batches as $b): ?><option value="<?= $b['id'] ?>">#<?= $b['id'] ?> <?= esc($b['filename']) ?></option><?php endforeach ?></select></div>
        </form>
    </div>
    <div class="card-body">
        <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
            'endpoint' => 'attendees', 'filters' => ['slam_id' => (int) $slam['id']], 'filterForm' => '#att-filters', 'exportLink' => '#att-export',
            'order' => [[1, 'asc']], 'empty' => 'No attendees yet. Import a CSV or add people one by one.',
            'columns' => [
                ['data' => 'id', 'title' => '#', 'priority' => 50],
                ['data' => 'name', 'title' => 'Name', 'render' => 'ml', 'priority' => 1],
                ['data' => 'badge_id', 'title' => 'ID', 'priority' => 2],
                ['data' => 'user_type', 'title' => 'Type', 'render' => 'user_type', 'priority' => 4],
                ['data' => 'mobile', 'title' => 'Mobile', 'orderable' => false, 'priority' => 6],
                ['data' => 'email', 'title' => 'Email', 'orderable' => false, 'priority' => 8],
                ['data' => 'checked_in', 'title' => 'Check-in', 'render' => 'checkedin', 'priority' => 3],
                ['data' => 'badge_print_count', 'title' => 'Printed', 'priority' => 9],
                ['data' => 'is_active', 'title' => 'Status', 'render' => 'active', 'priority' => 7],
            ],
            'actions' => [
                ['label' => 'Edit / reissue ID', 'icon' => 'action.edit', 'href' => '/admin/attendees/{id}/edit'],
                ['label' => 'Timeline', 'icon' => 'nav.log', 'href' => '/admin/activity-log?target_type=attendee&target_id={id}'],
                ['label' => 'Late check-in (after Gate Close)', 'icon' => 'nav.checkins', 'post' => '/api/checkin/late-override', 'body' => ['attendee_id' => '{id}'], 'reason' => true, 'when' => '!checked_in'],
                ['label' => 'Force check-out', 'icon' => 'action.logout', 'post' => '/api/force-checkout/{id}', 'reason' => true, 'danger' => true, 'when' => 'checked_in'],
            ],
        ]), 'attr') ?>'></table>
    </div>
</div>
<?php if ($batches !== []): ?>
<div class="card mt-3">
    <div class="card-header"><h3 class="card-title">Import batches</h3></div>
    <div class="card-body">
        <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
            'endpoint' => 'import-batches', 'filters' => ['slam_id' => (int) $slam['id']], 'order' => [[0, 'desc']], 'pii' => false,
            'columns' => [
                ['data' => 'id', 'title' => '#', 'priority' => 1],
                ['data' => 'created_at', 'title' => 'When', 'render' => 'datetime', 'priority' => 3],
                ['data' => 'filename', 'title' => 'File', 'priority' => 2],
                ['data' => 'status', 'title' => 'Status', 'render' => 'status', 'priority' => 4],
                ['data' => 'rows_created', 'title' => 'Created', 'orderable' => false, 'priority' => 5],
                ['data' => 'rows_updated', 'title' => 'Updated', 'orderable' => false, 'priority' => 6],
                ['data' => 'rows_skipped', 'title' => 'Skipped', 'orderable' => false, 'priority' => 7],
                ['data' => 'uploaded_by', 'title' => 'By', 'orderable' => false, 'priority' => 8],
            ],
            'actions' => [
                ['label' => 'Export badges CSV', 'icon' => 'action.export', 'href' => '/admin/slams/' . (int) $slam['id'] . '/attendees/export.csv?batch={id}'],
                ['label' => 'Deactivate whole batch', 'icon' => 'action.delete', 'post' => '/api/import-batches/{id}/deactivate', 'danger' => true,
                    'confirm' => 'Deactivate every attendee imported in this batch? Only possible if nobody in it has checked in or reviewed.'],
            ],
        ]), 'attr') ?>'></table>
    </div>
</div>
<?php endif ?>
<?= $this->endSection() ?>
