<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?>
<div class="page-header"><div class="container-xl"><h2 class="page-title">Presenters</h2>
<div class="text-secondary">People are reusable across slams (for example a regional presenter who reaches the final). Add them to a slam from its Presenters tab.</div></div></div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="card"><div class="card-body">
    <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
        'endpoint' => 'presenters', 'order' => [[2, 'asc']], 'pii' => false,
        'columns' => [
            ['data' => 'id', 'title' => '#', 'priority' => 50],
            ['data' => 'thumb', 'title' => '', 'render' => 'thumb', 'orderable' => false, 'priority' => 4],
            ['data' => 'name', 'title' => 'Name', 'render' => 'ml', 'priority' => 1],
            ['data' => 'designation', 'title' => 'Designation / institution', 'priority' => 5],
            ['data' => 'slams', 'title' => 'Slams', 'orderable' => false, 'priority' => 3],
            ['data' => 'is_active', 'title' => 'Status', 'render' => 'active', 'priority' => 6],
        ],
        'actions' => [['label' => 'Edit', 'icon' => 'action.edit', 'href' => '/admin/presenters/{id}/edit']],
    ]), 'attr') ?>'></table>
</div></div>
<?= $this->endSection() ?>
