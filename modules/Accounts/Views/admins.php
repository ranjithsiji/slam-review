<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?>
<div class="page-header"><div class="container-fluid"><div class="row g-2 align-items-center">
    <div class="col"><?= view('Modules\Core\Views\partials\breadcrumb', ['crumbs' => $breadcrumbs]) ?><h2 class="page-title">Administrators and slam operators</h2><div class="text-secondary">Administrators run the whole system; a slam operator runs one slam. Volunteers are managed under <a href="<?= site_url('admin/volunteers') ?>">Volunteers</a>.</div></div>
    <div class="col-auto"><a href="<?= site_url('admin/users/new') ?>" class="btn btn-primary"><?= icon('action.add') ?>Add administrator</a> <a href="<?= site_url('admin/users/new?role=operator') ?>" class="btn"><?= icon('action.add') ?>Add slam operator</a></div>
</div></div></div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="card card-accent accent-blue">
    <div class="card-body">
        <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
            'endpoint' => 'admins', 'order' => [[1, 'asc']],
            'columns' => [
                ['data' => 'id', 'title' => '#', 'priority' => 50],
                ['data' => 'name', 'title' => 'Name', 'priority' => 1],
                ['data' => 'username', 'title' => 'Username', 'priority' => 3],
                ['data' => 'role_label', 'title' => 'Role', 'priority' => 2, 'orderable' => false],
                ['data' => 'slam_name', 'title' => 'Slam', 'priority' => 4, 'orderable' => false],
                ['data' => 'email', 'title' => 'Email', 'priority' => 5],
                ['data' => 'is_active', 'title' => 'Status', 'render' => 'active', 'priority' => 2],
                ['data' => 'last_login_at', 'title' => 'Last sign-in', 'render' => 'datetime', 'priority' => 4],
            ],
            'actions' => [['label' => 'Edit / reset password', 'icon' => 'action.edit', 'href' => '/admin/users/{id}/edit']],
        ]), 'attr') ?>'></table>
    </div>
</div>
<?= $this->endSection() ?>
