<?php $this->setVar('entry', 'frontend/entries/stats.js'); ?>
<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'stats']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="mb-3"><?= in_array($slam['status'], ['marks_calculated', 'ended'], true) ? '' : status_badge('marks', 'provisional') ?> <span class="text-secondary small">Computed from the current answers.</span></div>
<div class="row row-cards" data-stats="<?= site_url('api/slams/' . $slam['id'] . '/stats') ?>">
    <div class="col-12 col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Final marks by group</h3></div><div class="card-body"><canvas data-chart="groups" height="260" aria-label="Average marks per performance by group" role="img"></canvas></div></div></div>
    <div class="col-12 col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Score distribution (1–10)</h3></div><div class="card-body"><canvas data-chart="dist" height="260" aria-label="How often each score was given" role="img"></canvas></div></div></div>
    <div class="col-12 col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Participation per performance</h3></div><div class="card-body"><canvas data-chart="participation" height="260" aria-label="Reviews per performance" role="img"></canvas></div></div></div>
    <div class="col-12 col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Summary</h3></div><div class="card-body" data-summary></div></div></div>
    <div class="col-12">
        <div class="card"><div class="card-header"><h3 class="card-title">Per-question averages by group</h3></div><div class="card-body">
            <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
                'endpoint' => 'mark-stats', 'filters' => ['slam_id' => (int) $slam['id']], 'order' => [[0, 'asc']], 'pii' => false,
                'columns' => [
                    ['data' => 'presenter_name', 'title' => 'Presenter', 'render' => 'ml', 'priority' => 1],
                    ['data' => 'audience', 'title' => 'Set', 'priority' => 4],
                    ['data' => 'question', 'title' => 'Question', 'render' => 'ml', 'priority' => 2],
                    ['data' => 'avg_user', 'title' => 'Users', 'render' => 'number2', 'priority' => 3],
                    ['data' => 'avg_judge', 'title' => 'Judges', 'render' => 'number2', 'priority' => 3],
                    ['data' => 'avg_complete', 'title' => 'Complete', 'render' => 'number2', 'priority' => 5],
                ],
            ]), 'attr') ?>'></table>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>
