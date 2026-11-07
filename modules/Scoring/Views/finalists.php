<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'finalists']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
/** @var array $slam @var list<array> $rows @var int $count @var bool $hasMarks */
$sid    = (int) $slam['id'];
$chosen = array_values(array_filter($rows, static fn ($r) => $r['chosen']));
$byRank = $rows;
usort($byRank, static fn ($a, $b) => ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX));
$topIds = array_column(array_slice(array_filter($byRank, static fn ($r) => $r['rank'] !== null), 0, $count), 'id');
?>
<div class="row row-cards">
    <div class="col-12 col-lg-5">
        <div class="card card-accent accent-green">
            <div class="card-header"><h3 class="card-title"><?= icon('nav.marks', 'me-2') ?>Selected for the final</h3></div>
            <div class="card-body">
                <?php if ($chosen === []): ?>
                    <p class="text-secondary mb-0">Nobody chosen yet. Tick <?= $count ?> presenters on the right and save.</p>
                <?php else: ?>
                    <p class="small text-secondary">Names in alphabetical order. No positions or marks are shown or printed.</p>
                    <ol class="list-unstyled mb-3">
                        <?php foreach ($chosen as $r): ?>
                            <li class="mb-2"><div class="fw-bold fs-3 ml-text"><?= esc($r['name']) ?></div><div class="small text-secondary ml-text"><?= esc($r['designation'] ?? '') ?></div></li>
                        <?php endforeach ?>
                    </ol>
                    <a class="btn btn-primary" href="<?= site_url("admin/slams/{$sid}/finalists.pdf") ?>" target="_blank" rel="noopener"><?= icon('action.print') ?>Print finalists (A4)</a>
                <?php endif ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-7">
        <form class="card" method="post" action="<?= site_url("admin/slams/{$sid}/finalists") ?>" id="finalists-form" data-finalists="<?= (int) $count ?>">
            <?= csrf_field() ?>
            <div class="card-header"><h3 class="card-title">Choose <?= $count ?> presenters <span class="small text-secondary fw-normal">(another number only with a reason)</span></h3>
                <?php if ($hasMarks && $topIds !== []): ?><div class="card-actions"><button type="button" class="btn btn-sm" data-pick-top='<?= esc(json_encode($topIds), 'attr') ?>'>Tick the top <?= $count ?> by marks</button></div><?php endif ?>
            </div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead><tr><th></th><th>Presenter</th><th>Talk</th><?php if ($hasMarks): ?><th class="text-end">Marks /100</th><th class="text-end">Rank</th><?php endif ?></tr></thead>
                    <tbody>
                    <?php foreach ($byRank as $r): ?>
                        <tr>
                            <td><input class="form-check-input" type="checkbox" name="finalists[]" value="<?= (int) $r['id'] ?>" <?= $r['chosen'] ? 'checked' : '' ?> aria-label="<?= esc($r['name'], 'attr') ?>"></td>
                            <td><div class="fw-bold ml-text"><?= esc($r['name']) ?></div><div class="small text-secondary ml-text"><?= esc($r['designation'] ?? '') ?></div></td>
                            <td class="small ml-text"><?= esc($r['topic']) ?></td>
                            <?php if ($hasMarks): ?>
                                <td class="text-end"><?= $r['final_mark'] !== null ? number_format($r['final_mark'] * 10, 2) : '—' ?></td>
                                <td class="text-end"><?= $r['rank'] ?? '—' ?></td>
                            <?php endif ?>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                <?php if (! $hasMarks): ?><p class="small text-secondary">Marks have not been calculated yet; you can still choose by hand.</p><?php endif ?>
                <label class="form-label" for="note">Note (kept in the activity log; required if you choose more or fewer than <?= $count ?>)</label>
                <input class="form-control mb-3" id="note" name="note" maxlength="255" placeholder="e.g. tie at 5th place: both go to the final">
                <div class="d-flex align-items-center gap-3"><button class="btn btn-primary" type="submit"><?= icon('action.save') ?>Save finalists</button><span class="small text-secondary" data-pick-count></span></div>
            </div>
        </form>
    </div>
</div>
<?= $this->endSection() ?>
