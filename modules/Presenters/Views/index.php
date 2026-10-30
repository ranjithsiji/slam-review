<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'presenters']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="<?= site_url('admin/slams/' . $slam['id'] . '/presenters/new') ?>" class="btn btn-primary"><?= icon('action.add') ?>Add presenter</a>
    <a href="<?= site_url('admin/slams/' . $slam['id'] . '/schedule') ?>" class="btn"><?= icon('nav.schedule') ?>Schedule maker</a>
</div>
<div class="card"><div class="card-body">
    <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
        'endpoint' => 'performances', 'filters' => ['slam_id' => (int) $slam['id']], 'order' => [[4, 'asc']], 'pii' => false,
        'empty' => 'No presenters yet. Add the first one.',
        'columns' => [
            ['data' => 'id', 'title' => '#', 'priority' => 50],
            ['data' => 'thumb', 'title' => '', 'render' => 'thumb', 'orderable' => false, 'priority' => 5],
            ['data' => 'presenter_name', 'title' => 'Presenter', 'render' => 'ml', 'priority' => 1],
            ['data' => 'topic', 'title' => 'Topic', 'render' => 'ml', 'priority' => 2],
            ['data' => 'planned_start_at', 'title' => 'Planned', 'render' => 'time', 'priority' => 3],
            ['data' => 'slot_status', 'title' => 'Slot', 'render' => 'slot', 'priority' => 6],
            ['data' => 'reviews', 'title' => 'Reviews', 'priority' => 7],
            ['data' => 'is_active', 'title' => 'Status', 'render' => 'active', 'priority' => 8],
        ],
        'actions' => [
            ['label' => 'Edit topic & abstract', 'icon' => 'action.edit', 'href' => '/admin/performances/{id}/edit'],
            ['label' => 'Edit presenter (photo, profile)', 'icon' => 'person.user', 'href' => '/admin/presenters/{presenter_id}/edit?back=slam'],
        ],
    ]), 'attr') ?>'></table>
</div></div>
<?= $this->endSection() ?>
