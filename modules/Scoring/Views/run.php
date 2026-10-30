<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'marks']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $c = json_decode($run['counts_snapshot'], true); ?>
<div class="card mb-3 card-accent accent-orange"><div class="card-body">
    <h3 class="card-title">Mark run #<?= (int) $run['id'] ?> <?= (int) $run['is_current'] ? status_badge('marks', 'official', 'Current') : '' ?> <?= (int) $run['is_final'] ? '<span class="badge bg-dark-lt">Final</span>' : '' ?></h3>
    <div class="text-secondary"><?= esc(local_time($run['calculated_at'])) ?><?= $run['note'] ? ' · ' . esc($run['note']) : '' ?></div>
    <div class="small mt-2">Weights: general reviewers <?= (float) $weights['w_general'] ?>%, representatives <?= (float) $weights['w_representative'] ?>%, judges <?= (float) $weights['w_judge'] ?>% ·
        <?= (int) ($c['reviews'] ?? 0) ?> reviews · <?= (int) ($c['representatives'] ?? 0) ?> representatives (from <?= (int) ($c['complete_reviewers'] ?? 0) ?> who reviewed everything)<?= $previous_run_id ? ' · compared with run #' . (int) $previous_run_id : '' ?></div>
</div></div>
<div class="card"><div class="table-responsive">
    <table class="table table-vcenter card-table">
        <thead><tr><th>Rank</th><th>Presenter</th><th class="text-end">General</th><th class="text-end">Represent.</th><th class="text-end">Judges</th><th class="text-end">Final</th><th class="text-end">Change</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
            <tr>
                <td><?= $r['rank'] !== null ? (int) $r['rank'] : '—' ?><?= $r['prev_rank'] !== null && (int) $r['prev_rank'] !== (int) $r['rank'] ? ' <span class="text-secondary small">(was ' . (int) $r['prev_rank'] . ')</span>' : '' ?></td>
                <td><div class="ml-text"><?= esc($r['presenter_name']) ?></div><div class="small text-secondary ml-text"><?= esc($r['topic']) ?></div></td>
                <?php foreach (['avg_general', 'avg_representative', 'avg_judge', 'final_mark'] as $k): ?>
                    <td class="text-end"><?= $r[$k] !== null ? number_format((float) $r[$k], 2) : '—' ?></td>
                <?php endforeach ?>
                <td class="text-end"><?= $r['delta'] === null ? '—' : ($r['delta'] > 0 ? '<span class="text-green">+' : ($r['delta'] < 0 ? '<span class="text-red">' : '<span>')) . number_format((float) $r['delta'], 2) . '</span>' ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div></div>
<div class="mt-3"><?= view('Modules\Scoring\Views\breakdown', ['rows' => $results, 'weights' => $weights, 'questions' => $questions]) ?></div>
<?= $this->endSection() ?>
