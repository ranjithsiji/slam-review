<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'presenters']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="card mb-3"><div class="card-body d-flex flex-wrap align-items-center gap-3">
    <div class="me-auto">
        <div class="fw-bold fs-3">Presenter pool</div>
        <div class="text-secondary"><?= (int) $total ?> presenter<?= $total === 1 ? '' : 's' ?> in the pool<?php if ($total > 0): ?> ·
            <?= $unscheduled === 0 ? '<span class="text-green">all are in the schedule</span>' : '<span class="text-orange">' . (int) $unscheduled . ' not in the schedule yet</span>' ?><?php endif ?></div>
        <div class="small text-secondary mt-1">Flow: add presenters to the pool → build the schedule from the pool → publish it.</div>
    </div>
    <a href="<?= site_url('admin/slams/' . $slam['id'] . '/presenters/new') ?>" class="btn <?= $total === 0 ? 'btn-primary' : '' ?>"><?= icon('action.add') ?>Add presenter</a>
    <a href="<?= site_url('admin/slams/' . $slam['id'] . '/schedule') ?>" class="btn <?= $total > 0 ? 'btn-primary' : '' ?>"><?= icon('nav.schedule') ?>Build the schedule</a>
</div></div>
<div class="card"><div class="card-header">
    <form id="perf-filters" class="d-flex gap-2 w-100"><select name="scheduled" class="form-select" style="max-width:260px" aria-label="Schedule state"><option value="">All presenters</option><option value="0">Still in the pool (not scheduled)</option><option value="1">In the schedule</option></select></form>
</div><div class="card-body">
    <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
        'endpoint' => 'performances', 'filters' => ['slam_id' => (int) $slam['id']], 'filterForm' => '#perf-filters', 'order' => [[4, 'asc']], 'pii' => false,
        'empty' => 'The pool is empty. Add the first presenter; no schedule time is needed.',
        'columns' => [
            ['data' => 'id', 'title' => '#', 'priority' => 50],
            ['data' => 'thumb', 'title' => '', 'render' => 'thumb', 'orderable' => false, 'priority' => 5],
            ['data' => 'presenter_name', 'title' => 'Presenter', 'render' => 'ml', 'priority' => 1],
            ['data' => 'topic', 'title' => 'Topic', 'render' => 'ml', 'priority' => 2],
            ['data' => 'planned_start_at', 'title' => 'Planned time', 'render' => 'time', 'priority' => 6],
            ['data' => 'slot_status', 'title' => 'Schedule', 'render' => 'slot', 'priority' => 3],
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
