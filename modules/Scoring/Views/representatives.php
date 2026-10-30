<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'representatives']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$sid  = (int) $slam['id'];
$meta = $lastDraw ? json_decode((string) $lastDraw['meta'], true) : null;
$cols = [
    ['data' => 'id', 'title' => '#', 'priority' => 50],
    ['data' => 'name', 'title' => 'Name', 'render' => 'ml', 'priority' => 1],
    ['data' => 'badge_id', 'title' => 'ID', 'priority' => 2],
    ['data' => 'mobile', 'title' => 'Mobile', 'orderable' => false, 'priority' => 4],
    ['data' => 'email', 'title' => 'Email', 'orderable' => false, 'priority' => 6],
    ['data' => 'reviews', 'title' => 'Reviews', 'priority' => 5],
];
?>
<div class="row row-cards mb-3">
    <div class="col-12 col-lg-7">
        <div class="card h-100 card-accent accent-pink">
            <div class="card-header"><h3 class="card-title"><?= icon('action.shuffle', 'me-2') ?>Draw representatives</h3></div>
            <div class="card-body">
                <p class="text-secondary">Representatives are drawn at random from the audience users who reviewed <strong>every</strong> performance. They form their own scoring group with its own weight; their reviews no longer count in the general reviewers' average. After the draw you can add or remove people below.</p>
                <?php if ($locked !== null): ?>
                    <div class="alert alert-info mb-3"><?= icon('status.lock', 'me-1') ?><?= esc($locked) ?></div>
                <?php endif ?>
                <form id="draw-form" class="row g-2 align-items-end">
                    <div class="col-6 col-sm-4">
                        <label class="form-label" for="count">How many</label>
                        <input id="count" name="count" type="number" min="1" max="1000" step="1" required class="form-control" value="<?= (int) $count ?>" <?= $locked ? 'disabled' : '' ?>>
                    </div>
                    <div class="col-6 col-sm-8">
                        <button type="button" class="btn btn-primary w-100" data-api-post="<?= site_url("api/slams/{$sid}/representatives/draw") ?>" data-form="#draw-form"
                            data-title="Draw representatives" data-confirm="<?= $selected > 0 ? 'This replaces the current list of ' . (int) $selected . ' representative(s), including people you added by hand.' : 'People are picked at random from everyone who reviewed every performance.' ?>"
                            data-reload <?= $locked || $pool === 0 ? 'disabled' : '' ?>><?= icon('action.shuffle') ?><?= $selected > 0 ? 'Draw again' : 'Draw at random' ?></button>
                    </div>
                </form>
                <?php if ($meta): ?>
                    <p class="small text-secondary mt-3 mb-0">Last draw: <?= esc(local_time($lastDraw['occurred_at'])) ?> by <?= esc($lastDraw['actor_label']) ?> · <?= (int) ($meta['requested'] ?? 0) ?> requested from <?= (int) ($meta['pool'] ?? 0) ?> · seed <?= (int) ($meta['seed'] ?? 0) ?> (recorded in the activity log, so the draw can be verified).</p>
                <?php endif ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-5">
        <div class="card h-100 card-accent-left accent-pink"><div class="card-body">
            <div class="row g-3 text-center">
                <div class="col-6"><div class="h1 mb-0"><?= (int) $pool ?></div><div class="text-secondary small">reviewed every performance</div></div>
                <div class="col-6"><div class="h1 mb-0"><?= (int) $selected ?></div><div class="text-secondary small">representatives</div></div>
            </div>
            <?php if ($selected > 0 && $selected < $count): ?><div class="alert alert-warning mt-3 mb-0"><?= icon('status.warning', 'me-1') ?>Fewer representatives than requested (<?= (int) $count ?>).</div><?php endif ?>
            <p class="small text-secondary mt-3 mb-0">Changes take effect in the next marks calculation; existing mark runs never change. <a href="<?= site_url("admin/slams/{$sid}/mark-config") ?>">Group weights</a></p>
        </div></div>
    </div>
</div>

<div class="card mb-3 card-accent accent-pink">
    <div class="card-header"><h3 class="card-title"><?= icon('nav.representatives', 'me-2') ?>Selected representatives</h3><div class="card-actions"><a class="btn btn-sm" href="#" id="reps-export"><?= icon('action.export') ?>Export CSV</a></div></div>
    <div class="card-body">
        <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
            'endpoint' => 'complete-reviewers', 'filters' => ['slam_id' => $sid, 'panel' => 'selected'], 'exportLink' => '#reps-export', 'order' => [[1, 'asc']],
            'empty' => 'No representatives yet.',
            'columns' => [...$cols, ['data' => 'rep_source', 'title' => 'How', 'orderable' => false, 'priority' => 3]],
            'actions' => $locked ? [] : [[
                'label' => 'Remove from representatives', 'icon' => 'action.delete', 'danger' => true, 'reason' => true,
                'post' => "/api/slams/{$sid}/representatives/{id}/remove", 'success' => 'Removed.', 'reloadPage' => true,
            ]],
        ]), 'attr') ?>'></table>
    </div>
</div>

<div class="card card-accent accent-orange">
    <div class="card-header"><h3 class="card-title">Everyone who reviewed every performance</h3><div class="card-actions"><a class="btn btn-sm" href="#" id="pool-export"><?= icon('action.export') ?>Export CSV</a></div></div>
    <div class="card-body">
        <p class="small text-secondary">Audience users only (judges are their own group). Performances that were skipped do not count. This list is also usable for prizes or a raffle.</p>
        <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
            'endpoint' => 'complete-reviewers', 'filters' => ['slam_id' => $sid, 'panel' => 'all'], 'exportLink' => '#pool-export', 'order' => [[1, 'asc']],
            'empty' => 'Nobody has reviewed every performance yet.',
            'columns' => [...$cols, ['data' => 'is_representative', 'title' => 'Representative', 'render' => 'bool', 'priority' => 3]],
            'actions' => $locked ? [] : [[
                'label' => 'Add to representatives', 'icon' => 'action.add', 'when' => '!is_representative',
                'post' => "/api/slams/{$sid}/representatives/{id}/add", 'success' => 'Added.', 'reloadPage' => true,
            ]],
        ]), 'attr') ?>'></table>
    </div>
</div>
<?= $this->endSection() ?>
