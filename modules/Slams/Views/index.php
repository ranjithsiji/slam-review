<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?>
<div class="page-header"><div class="container-xl"><div class="row g-2 align-items-center">
    <div class="col"><h2 class="page-title">Slams</h2></div>
    <div class="col-auto"><a href="<?= site_url('admin/slams/new') ?>" class="btn btn-primary"><?= icon('action.add') ?>New slam</a></div>
</div></div></div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="card"><div class="card-body">
    <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
        'endpoint' => 'slams', 'order' => [[5, 'asc']], 'pii' => false,
        'columns' => [
            ['data' => 'id', 'title' => '#', 'priority' => 50],
            ['data' => 'name', 'title' => 'Name', 'priority' => 1],
            ['data' => 'type', 'title' => 'Type', 'priority' => 6],
            ['data' => 'venue_name', 'title' => 'Venue', 'priority' => 5],
            ['data' => 'city', 'title' => 'City', 'priority' => 7],
            ['data' => 'opens_at', 'title' => 'Date', 'render' => 'datetime', 'priority' => 3],
            ['data' => 'status', 'title' => 'Phase', 'render' => 'phase', 'priority' => 2],
            ['data' => 'schedule_is_public', 'title' => 'Public', 'render' => 'bool', 'priority' => 8],
            ['data' => 'attendees', 'title' => 'Attendees', 'orderable' => false, 'priority' => 9],
        ],
        'actions' => [
            ['label' => 'Open', 'icon' => 'action.open', 'href' => '/admin/slams/{id}'],
            ['label' => 'Status & live controls', 'icon' => 'nav.status', 'href' => '/admin/slams/{id}/status'],
            ['label' => 'Edit', 'icon' => 'action.edit', 'href' => '/admin/slams/{id}/edit'],
        ],
    ]), 'attr') ?>'></table>
</div></div>
<?= $this->endSection() ?>
