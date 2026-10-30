<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'judges']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$sid      = (int) $slam['id'];
$current  = null;
foreach ($judges as $j) {
    if ((int) $j['id'] === $judge) {
        $current = $j;
    }
}
$fmt      = static fn (?float $v) => $v === null ? '' : rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
$expected = count($items) * count($judges);
$entered  = array_sum(array_map('count', $marks));
?>
<div class="card mb-3">
    <div class="card-body">
        <p class="mb-2">For this slam, judges mark on paper and you enter each judge's <strong>consolidated mark out of 10</strong> per performance here (one decimal allowed, e.g. 7.5; no 0). Leave a box empty for no mark; clearing a saved mark removes it.</p>
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <span class="badge <?= $entered === $expected && $expected > 0 ? 'bg-green-lt' : 'bg-orange-lt' ?>"><?= (int) $entered ?> of <?= (int) $expected ?> marks entered</span>
            <?php if ($run): ?><span class="small text-secondary"><?= icon('status.info', 'icon-inline me-1') ?>Marks were last calculated <?= esc(local_time($run['calculated_at'], 'd M H:i')) ?>. After entering marks, <a href="<?= site_url("admin/slams/{$sid}/marks") ?>">recalculate</a>.</span><?php endif ?>
        </div>
        <?php if ($locked !== null): ?><div class="alert alert-info mt-3 mb-0"><?= icon('status.lock', 'me-1') ?><?= esc($locked) ?></div><?php endif ?>
    </div>
</div>

<?php if ($judges === []): ?>
    <div class="empty"><p class="empty-title">No judges in this slam</p><p class="empty-subtitle text-secondary">Add attendees with type "judge" on the Attendees page.</p></div>
<?php elseif ($items === []): ?>
    <div class="empty"><p class="empty-title">No performances have run yet</p><p class="empty-subtitle text-secondary">Marks can be entered for performances whose review window was opened.</p></div>
<?php else: ?>
<div class="row row-cards">
    <div class="col-12 col-lg-5">
        <form method="get" class="mb-3">
            <label class="form-label" for="judge">Judge</label>
            <select id="judge" name="judge" class="form-select" data-autosubmit>
                <?php foreach ($judges as $j): $n = count($marks[(int) $j['id']] ?? []); ?>
                    <option value="<?= (int) $j['id'] ?>" <?= (int) $j['id'] === $judge ? 'selected' : '' ?>><?= esc($j['name']) ?> (<?= $n ?>/<?= count($items) ?>)</option>
                <?php endforeach ?>
            </select>
        </form>
        <?php if ($current): ?>
        <form method="post" action="<?= site_url("admin/slams/{$sid}/judge-marks") ?>" class="card" data-once>
            <?= csrf_field() ?>
            <input type="hidden" name="judge_id" value="<?= (int) $current['id'] ?>">
            <div class="card-header"><h3 class="card-title"><?= icon('person.judge', 'me-2') ?><span class="ml-text"><?= esc($current['name']) ?></span></h3></div>
            <div class="list-group list-group-flush">
                <?php foreach ($items as $i => $it): $key = 'marks.' . (int) $it['id']; $val = old('marks.' . (int) $it['id'], $fmt($marks[(int) $current['id']][(int) $it['id']] ?? null)); ?>
                    <div class="list-group-item">
                        <div class="row g-2 align-items-center">
                            <div class="col">
                                <label class="fw-bold ml-text d-block" for="m-<?= (int) $it['id'] ?>"><?= $i + 1 ?>. <?= esc($it['presenter_name']) ?></label>
                                <div class="small text-secondary ml-text text-truncate"><?= esc($it['topic']) ?></div>
                            </div>
                            <div class="col-auto" style="width:7.5rem">
                                <div class="input-group">
                                    <input id="m-<?= (int) $it['id'] ?>" name="marks[<?= (int) $it['id'] ?>]" type="text" inputmode="decimal" pattern="\d{1,2}([.,]\d)?" class="form-control text-end<?= is_invalid($errors, $key) ?>" value="<?= esc((string) $val) ?>" placeholder="—" <?= $locked ? 'disabled' : '' ?>>
                                    <span class="input-group-text">/10</span>
                                </div>
                            </div>
                        </div>
                        <?= field_error($errors, $key) ?>
                    </div>
                <?php endforeach ?>
            </div>
            <div class="card-footer sticky-actions text-end"><button class="btn btn-primary" type="submit" <?= $locked ? 'disabled' : '' ?>><?= icon('action.save') ?>Save <?= esc($current['name']) ?>'s marks</button></div>
        </form>
        <?php endif ?>
    </div>
    <div class="col-12 col-lg-7">
        <div class="card card-accent accent-orange">
            <div class="card-header"><h3 class="card-title">All judges' marks</h3></div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table table-sm">
                    <thead><tr><th>Performance</th><?php foreach ($judges as $j): ?><th class="text-end"><a href="?judge=<?= (int) $j['id'] ?>" class="ml-text"><?= esc($j['name']) ?></a></th><?php endforeach ?><th class="text-end">Average</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $i => $it): $row = array_values(array_filter(array_map(static fn ($j) => $marks[(int) $j['id']][(int) $it['id']] ?? null, $judges), static fn ($v) => $v !== null)); ?>
                        <tr>
                            <td class="ml-text"><?= $i + 1 ?>. <?= esc($it['presenter_name']) ?></td>
                            <?php foreach ($judges as $j): $v = $marks[(int) $j['id']][(int) $it['id']] ?? null; ?>
                                <td class="text-end<?= $v === null ? ' text-secondary' : '' ?>"><?= $v === null ? '—' : esc($fmt($v)) ?></td>
                            <?php endforeach ?>
                            <td class="text-end fw-bold"><?= $row === [] ? '—' : number_format(array_sum($row) / count($row), 2) ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif ?>
<?= $this->endSection() ?>
