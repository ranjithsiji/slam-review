<?php $this->setVar('entry', 'frontend/entries/status.js'); ?>
<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'status', 'hideBadge' => true]) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div id="status-app"><div class="text-center text-secondary py-5"><div class="spinner-border" role="status"></div><div>Loading the status…</div></div></div>
<noscript><div class="alert alert-warning">Please enable JavaScript to see the live status.</div></noscript>
<div class="row row-cards mt-3">
    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Phase log</h3></div>
            <div class="card-body">
                <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
                    'endpoint' => 'phase-log', 'filters' => ['slam_id' => (int) $slam['id']], 'order' => [[0, 'desc']], 'refresh' => 10000, 'pii' => false,
                    'columns' => [
                        ['data' => 'id', 'title' => '#', 'priority' => 50],
                        ['data' => 'changed_at', 'title' => 'When', 'render' => 'time', 'priority' => 1],
                        ['data' => 'from_status', 'title' => 'From', 'render' => 'phase', 'orderable' => false, 'priority' => 4],
                        ['data' => 'to_status', 'title' => 'To', 'render' => 'phase', 'orderable' => false, 'priority' => 2],
                        ['data' => 'trigger', 'title' => 'Trigger', 'priority' => 3],
                        ['data' => 'by', 'title' => 'By', 'orderable' => false, 'priority' => 5],
                        ['data' => 'note', 'title' => 'Note', 'orderable' => false, 'priority' => 6],
                    ],
                ]), 'attr') ?>'></table>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Live activity</h3><div class="card-actions"><a href="<?= site_url('admin/activity-log?slam_id=' . $slam['id']) ?>" class="btn btn-sm">Full log</a></div></div>
            <div class="card-body">
                <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
                    'endpoint' => 'activity-log', 'filters' => ['slam_id' => (int) $slam['id']], 'order' => [[0, 'desc']], 'refresh' => 6000, 'pii' => false,
                    'columns' => [
                        ['data' => 'id', 'title' => '#', 'priority' => 50],
                        ['data' => 'occurred_at', 'title' => 'When', 'render' => 'time', 'priority' => 1],
                        ['data' => 'actor_label', 'title' => 'Who', 'priority' => 3],
                        ['data' => 'action', 'title' => 'Action', 'priority' => 2],
                        ['data' => 'outcome', 'title' => 'Outcome', 'render' => 'outcome', 'priority' => 4],
                        ['data' => 'reason', 'title' => 'Reason', 'orderable' => false, 'priority' => 5],
                    ],
                ]), 'attr') ?>'></table>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
<?= $this->section('boot') ?><?= boot_json(['slam_id' => (int) $slam['id']]) ?><?= $this->endSection() ?>
