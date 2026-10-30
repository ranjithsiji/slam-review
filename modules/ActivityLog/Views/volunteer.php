<?= $this->extend('Modules\Core\Views\layouts\volunteer') ?>
<?= $this->section('content') ?>
<h2>Check-in activity — <?= esc($slam['name']) ?></h2>
<div class="card"><div class="card-body">
    <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
        'endpoint' => 'activity-log', 'order' => [[0, 'desc']], 'refresh' => 10000,
        'columns' => [
            ['data' => 'id', 'title' => '#', 'priority' => 50],
            ['data' => 'occurred_at', 'title' => 'When', 'render' => 'time', 'priority' => 1],
            ['data' => 'actor_label', 'title' => 'Who', 'priority' => 3],
            ['data' => 'action', 'title' => 'Action', 'priority' => 2],
            ['data' => 'outcome', 'title' => 'Outcome', 'render' => 'outcome', 'priority' => 4],
            ['data' => 'reason', 'title' => 'Reason', 'orderable' => false, 'priority' => 5],
        ],
    ]), 'attr') ?>'></table>
</div></div>
<?= $this->endSection() ?>
