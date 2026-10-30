<?php $this->setVar('entry', 'frontend/entries/volunteer.js'); ?>
<?= $this->extend('Modules\Core\Views\layouts\\' . $layout) ?>
<?= $this->section('content') ?>
<div id="volunteer-app"><div class="text-center text-secondary py-5"><div class="spinner-border" role="status"></div><div>Loading the desk…</div></div></div>
<div class="card mt-3">
    <div class="card-header"><h3 class="card-title">Recent check-ins</h3></div>
    <div class="card-body">
        <table id="recent-checkins" class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
            'endpoint' => 'checkins', 'filters' => ['slam_id' => (int) $slam['id']], 'order' => [[0, 'desc']], 'refresh' => 10000,
            'columns' => [
                ['data' => 'id', 'title' => '#', 'priority' => 50],
                ['data' => 'name', 'title' => 'Name', 'render' => 'ml', 'priority' => 1],
                ['data' => 'badge_id', 'title' => 'ID', 'priority' => 3],
                ['data' => 'user_type', 'title' => 'Type', 'render' => 'user_type', 'priority' => 5],
                ['data' => 'checked_in_at', 'title' => 'In', 'render' => 'time', 'priority' => 4],
                ['data' => 'checked_out_at', 'title' => 'Out', 'render' => 'time', 'priority' => 6],
                ['data' => 'checkout_type', 'title' => 'Checkout', 'priority' => 7],
            ],
            'actions' => [['label' => 'Force check-out', 'icon' => 'action.logout', 'post' => '/api/force-checkout/{attendee_id}', 'reason' => true, 'danger' => true, 'when' => '!checked_out_at']],
        ]), 'attr') ?>'></table>
    </div>
</div>
<?= $this->endSection() ?>
<?= $this->section('boot') ?><?= boot_json(['slam_id' => (int) $slam['id'], 'is_admin' => session('auth')['type'] === 'admin']) ?><?= $this->endSection() ?>
