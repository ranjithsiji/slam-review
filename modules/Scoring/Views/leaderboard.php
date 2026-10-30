<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'leaderboard']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$n    = static fn (?float $v, int $dp = 2) => $v === null ? '—' : number_format($v, $dp);
$sid  = (int) $slam['id'];
$tone = ['general' => 'blue', 'representative' => 'purple', 'judge' => 'orange'];
$medal = [1 => 'yellow', 2 => 'secondary', 3 => 'orange'];
$pending = $judging['timing'] === 'end_of_slam' && $judgeProgress['entered'] < $judgeProgress['expected'];
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <?= $official ? '<span class="badge bg-green-lt">' . icon('status.ok', 'icon-inline me-1') . 'Official marks</span>' : '<span class="badge bg-orange-lt">' . icon('status.warning', 'icon-inline me-1') . 'Provisional: calculate the marks to make them official</span>' ?>
    <?php if ($pending): ?><span class="badge bg-orange-lt"><?= icon('person.judge', 'icon-inline me-1') ?>Judges' marks: <?= (int) $judgeProgress['entered'] ?> of <?= (int) $judgeProgress['expected'] ?> entered</span><?php endif ?>
    <span class="small text-secondary ms-md-2">Weights: General reviewers <?= $n((float) $weights['w_general'], 0) ?>% · Representatives <?= $n((float) $weights['w_representative'], 0) ?>% · Judges <?= $n((float) $weights['w_judge'], 0) ?>%</span>
    <span class="ms-auto d-flex gap-2">
        <a class="btn btn-sm" href="<?= site_url("admin/slams/{$sid}/scores") ?>"><?= icon('action.open') ?>Drill down</a>
        <a class="btn btn-sm" href="<?= site_url("admin/slams/{$sid}/marks") ?>"><?= icon('action.calculate') ?>Calculate marks</a>
    </span>
</div>

<?php if ($rows === []): ?>
    <div class="empty"><p class="empty-title">No performances yet</p></div>
<?php else: ?>
<div class="card card-accent accent-green">
    <div class="card-header"><h3 class="card-title"><?= icon('nav.marks', 'me-2') ?>Presenter leaderboard</h3></div>
    <div class="list-group list-group-flush">
        <?php foreach ($rows as $r): $b = $r['breakdown']; $rank = $r['rank'] !== null ? (int) $r['rank'] : null; $final = $b['final']; ?>
            <div class="list-group-item">
                <div class="d-flex align-items-center gap-3">
                    <span class="badge bg-<?= $medal[$rank] ?? 'secondary' ?>-lt fs-3 text-center" style="min-width:3rem" title="Rank"><?= $rank !== null ? $rank : '—' ?></span>
                    <?php if (! empty($r['photo'])): ?><img class="avatar avatar-md avatar-photo" src="<?= base_url($r['photo']) ?>" alt="" loading="lazy"><?php else: ?><span class="avatar avatar-md"><?= icon('person.user') ?></span><?php endif ?>
                    <div class="flex-fill min-w-0">
                        <div class="fw-bold ml-text text-truncate"><?= esc($r['presenter_name']) ?></div>
                        <div class="small text-secondary ml-text text-truncate"><?= esc($r['topic']) ?></div>
                        <div class="progress progress-sm mt-1" role="img" aria-label="<?= $n($final) ?> out of 10"><div class="progress-bar bg-<?= $medal[$rank] ?? 'primary' ?>" style="width:<?= $final === null ? 0 : max(0, min(100, $final * 10)) ?>%"></div></div>
                    </div>
                    <div class="text-end"><div class="h2 mb-0"><?= $n($final) ?></div><div class="small text-secondary">/ 10 · <span data-percent><?= $final === null ? '—' : $n($final * 10, 1) . '%' ?></span></div></div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-2 ps-md-5">
                    <?php foreach ($b['groups'] as $g => $x): ?>
                        <span class="badge bg-<?= $tone[$g] ?>-lt" title="<?= esc($x['label']) ?>"><?= esc($x['label']) ?> <?= $n($x['avg'] === null ? null : (float) $x['avg']) ?></span>
                    <?php endforeach ?>
                </div>
            </div>
        <?php endforeach ?>
    </div>
</div>
<?php endif ?>
<?= $this->endSection() ?>
