<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'checkins']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-primary" href="<?= site_url('volunteer?slam=' . $slam['id']) ?>"><?= icon('qr.scan') ?>Open desk (scan / search)</a>
    <a class="btn" href="#" id="ci-export"><?= icon('action.export') ?>Export CSV</a>
</div>
<div class="card">
    <div class="card-header">
        <form id="ci-filters"><select name="open" class="form-select" aria-label="Show" style="max-width:240px">
            <option value="">All check-ins</option><option value="1">Currently inside</option><option value="0">Checked out</option>
        </select></form>
    </div>
    <div class="card-body">
        <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
            'endpoint' => 'checkins', 'filters' => ['slam_id' => (int) $slam['id']], 'filterForm' => '#ci-filters', 'exportLink' => '#ci-export',
            'order' => [[0, 'desc']], 'refresh' => 8000,
            'columns' => [
                ['data' => 'id', 'title' => '#', 'priority' => 50],
                ['data' => 'name', 'title' => 'Name', 'render' => 'ml', 'priority' => 1],
                ['data' => 'badge_id', 'title' => 'ID', 'priority' => 3],
                ['data' => 'user_type', 'title' => 'Type', 'render' => 'user_type', 'priority' => 5],
                ['data' => 'checked_in_at', 'title' => 'In', 'render' => 'datetime', 'priority' => 4],
                ['data' => 'checked_out_at', 'title' => 'Out', 'render' => 'datetime', 'priority' => 6],
                ['data' => 'checkout_type', 'title' => 'Checkout', 'priority' => 7],
                ['data' => 'by', 'title' => 'By', 'orderable' => false, 'priority' => 9],
                ['data' => 'claimed', 'title' => 'Phone linked', 'render' => 'bool', 'orderable' => false, 'priority' => 8],
                ['data' => 'late', 'title' => 'Late entry', 'render' => 'bool', 'orderable' => false, 'priority' => 10],
            ],
            'actions' => [
                ['label' => 'Force check-out', 'icon' => 'action.logout', 'post' => '/api/force-checkout/{attendee_id}', 'reason' => true, 'danger' => true, 'when' => '!checked_out_at'],
                ['label' => 'Timeline', 'icon' => 'nav.log', 'href' => '/admin/activity-log?target_type=attendee&target_id={attendee_id}'],
            ],
        ]), 'attr') ?>'></table>
    </div>
</div>
<?= $this->endSection() ?>
