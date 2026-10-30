<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'marks']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="d-flex mb-3"><p class="text-secondary me-auto mb-0">Audience users who reviewed every performance that was opened (skipped ones excluded). Usable for prizes or a raffle.</p>
<a class="btn" href="#" id="cr-export"><?= icon('action.export') ?>Export CSV</a></div>
<div class="card"><div class="card-body">
    <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
        'endpoint' => 'complete-reviewers', 'filters' => ['slam_id' => (int) $slam['id']], 'exportLink' => '#cr-export', 'order' => [[1, 'asc']],
        'columns' => [
            ['data' => 'id', 'title' => '#', 'priority' => 50],
            ['data' => 'name', 'title' => 'Name', 'render' => 'ml', 'priority' => 1],
            ['data' => 'badge_id', 'title' => 'ID', 'priority' => 2],
            ['data' => 'mobile', 'title' => 'Mobile', 'orderable' => false, 'priority' => 3],
            ['data' => 'email', 'title' => 'Email', 'orderable' => false, 'priority' => 5],
            ['data' => 'reviews', 'title' => 'Reviews', 'priority' => 4],
        ],
    ]), 'attr') ?>'></table>
</div></div>
<?= $this->endSection() ?>
