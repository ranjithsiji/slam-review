<?php /** @var array $slam  @var list<array> $items  @var list<array> $questions  @var array $judging  @var bool $open  @var list<string> $rowErrors */ ?>
<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'paper']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $sid = (int) $slam['id']; $selected = (int) (old('item_id') ?: service('request')->getGet('item')); ?>
<?php if (! $open): ?><div class="alert alert-info">Paper marks can be entered once reviewing has started.</div><?php endif ?>
<?php if ($rowErrors !== []): ?>
    <div class="alert alert-danger"><div class="fw-bold mb-1">Please fix these lines and try again:</div><ul class="mb-0"><?php foreach ($rowErrors as $e): ?><li><?= esc($e) ?></li><?php endforeach ?></ul></div>
<?php endif ?>
<div class="row row-cards">
    <div class="col-12 col-xl-6">
        <form class="card card-accent accent-blue h-100" method="post" action="<?= site_url("admin/slams/{$sid}/paper-marks/audience") ?>">
            <?= csrf_field() ?>
            <div class="card-header"><h3 class="card-title"><?= icon('action.edit', 'me-2') ?>Audience paper ballots</h3></div>
            <div class="card-body">
                <p class="small text-secondary">Marks for one talk, one line per person: <code>BADGE ID, mark 1, mark 2</code> (<?= count($questions) ?> mark<?= count($questions) === 1 ? '' : 's' ?>, each from 1 to <?= (int) ($questions[0]['max_score'] ?? 5) ?>). Paste as many lines as you have. A person entered twice for the same talk is updated (history kept).</p>
                <label class="form-label" for="item_id">Talk</label>
                <select id="item_id" name="item_id" class="form-select mb-3" required <?= $open ? '' : 'disabled' ?>>
                    <option value="">Choose…</option>
                    <?php foreach ($items as $i): ?><option value="<?= (int) $i['id'] ?>" <?= $selected === (int) $i['id'] ? 'selected' : '' ?>><?= esc($i['name']) ?> — <?= esc($i['topic']) ?><?= (int) $i['paper'] > 0 ? ' (' . (int) $i['paper'] . ' by hand)' : '' ?></option><?php endforeach ?>
                </select>
                <label class="form-label" for="lines">Marks</label>
                <textarea id="lines" name="lines" class="form-control font-monospace" rows="9" spellcheck="false" placeholder="KR48213, 4, 5&#10;MP90453, 3, 4" <?= $open ? '' : 'disabled' ?>><?= esc((string) old('lines')) ?></textarea>
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary" type="submit" <?= $open ? '' : 'disabled' ?>><?= icon('action.save') ?>Save these marks</button></div>
        </form>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card card-accent accent-purple mb-3">
            <div class="card-header"><h3 class="card-title"><?= icon('person.judge', 'me-2') ?>Judges' paper marks</h3></div>
            <div class="card-body">
                <?php if ($judging['timing'] === 'end_of_slam'): ?>
                    <p>Judges' marks are written on paper for this slam. Enter each judge's mark for every talk on the <a href="<?= site_url("admin/slams/{$sid}/judge-marks") ?>">Judges' marks</a> screen (a mark from 0.1 to 10, one decimal), or import them below.</p>
                <?php else: ?>
                    <p class="text-secondary mb-0">Judges mark live on their phones in this slam, so their marks are not entered by hand (change this in Mark configuration if they used paper).</p>
                <?php endif ?>
            </div>
        </div>
        <form class="card card-accent accent-green" method="post" action="<?= site_url("admin/slams/{$sid}/paper-marks/import") ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="card-header"><h3 class="card-title"><?= icon('action.export', 'me-2') ?>Import a CSV</h3></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="kind">The file contains</label>
                    <select id="kind" name="kind" class="form-select" <?= $open ? '' : 'disabled' ?>>
                        <option value="audience">Audience ballots</option>
                        <option value="judge" <?= $judging['timing'] === 'end_of_slam' ? '' : 'disabled' ?>>Judges' marks</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="file">CSV file (UTF-8, up to 2 MB)</label>
                    <input id="file" name="file" type="file" accept=".csv,text/csv" class="form-control" required <?= $open ? '' : 'disabled' ?>>
                </div>
                <p class="small text-secondary mb-1">Audience: <code>badge_id, presenter, q1, q2</code>. Judges: <code>badge_id, presenter, mark</code>. The presenter column is the presenter's name (or a <code>performance_id</code> column). The whole file is checked first: if any line is wrong, nothing is imported.</p>
                <a class="btn btn-sm" href="<?= site_url("admin/slams/{$sid}/paper-marks/template/audience.csv") ?>"><?= icon('action.export') ?>Audience template</a>
                <a class="btn btn-sm" href="<?= site_url("admin/slams/{$sid}/paper-marks/template/judge.csv") ?>"><?= icon('action.export') ?>Judges template</a>
            </div>
            <div class="card-footer text-end"><button class="btn btn-success" type="submit" <?= $open ? '' : 'disabled' ?>>Import</button></div>
        </form>
    </div>
</div>
<p class="small text-secondary mt-3">After the slam closes, every entry recalculates the marks automatically from the weights: group averages and the final mark are shown out of 100.</p>
<?= $this->endSection() ?>
