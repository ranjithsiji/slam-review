<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?>
<div class="page-header"><div class="container-xl"><div class="row g-2 align-items-center">
    <div class="col"><h2 class="page-title">Volunteers</h2><div class="text-secondary">Each volunteer is bound to one slam and can only see and act on that slam.</div></div>
    <div class="col-auto"><a href="<?= site_url('admin/volunteers/new') ?>" class="btn btn-primary"><?= icon('action.add') ?>Add volunteer</a></div>
</div></div></div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="card">
    <div class="card-header">
        <form id="vol-filters" class="row g-2 w-100">
            <div class="col-12 col-sm-6 col-md-4">
                <select name="slam_id" class="form-select" aria-label="Slam">
                    <option value="">All slams</option>
                    <?php foreach ($slams as $s): ?><option value="<?= $s['id'] ?>"><?= esc($s['name']) ?></option><?php endforeach ?>
                </select>
            </div>
        </form>
    </div>
    <div class="card-body">
        <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
            'endpoint' => 'volunteers', 'filterForm' => '#vol-filters', 'order' => [[1, 'asc']],
            'columns' => [
                ['data' => 'id', 'title' => '#', 'priority' => 50],
                ['data' => 'name', 'title' => 'Name', 'priority' => 1],
                ['data' => 'username', 'title' => 'Username', 'priority' => 3],
                ['data' => 'slam_name', 'title' => 'Slam', 'priority' => 2],
                ['data' => 'is_active', 'title' => 'Status', 'render' => 'active', 'priority' => 4],
            ],
            'actions' => [['label' => 'Edit', 'icon' => 'action.edit', 'href' => '/admin/volunteers/{id}/edit']],
        ]), 'attr') ?>'></table>
    </div>
</div>
<?= $this->endSection() ?>
