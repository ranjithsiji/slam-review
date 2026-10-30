<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'scores']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$n     = static fn (?float $v, int $dp = 2) => $v === null ? '—' : number_format($v, $dp);
$count = ['general' => 'n_general', 'representative' => 'n_representative', 'judge' => 'n_judge'];
$tone  = ['general' => 'blue', 'representative' => 'purple', 'judge' => 'orange'];
$detail = static function (array $r, string $g) use ($questions): array {
    $pq  = is_string($r['per_question'] ?? null) ? (json_decode($r['per_question'], true) ?: []) : ($r['per_question'] ?? []);
    $out = [];
    foreach ($pq as $qid => $groups) {
        $q = $questions[$qid] ?? null;
        if ($q !== null && ($groups[$g] ?? null) !== null) {
            $out[] = [$q['text'], (float) $groups[$g], (int) $q['max_score']];
        }
    }

    return $out;
};
$ranked = count(array_filter($rows, static fn ($r) => $r['rank'] !== null));
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <?= $official ? '<span class="badge bg-green-lt">' . icon('status.ok', 'icon-inline me-1') . 'Official marks</span>' : '<span class="badge bg-orange-lt">' . icon('status.warning', 'icon-inline me-1') . 'Provisional: not final until the marks are calculated</span>' ?>
    <span class="small text-secondary">
        Weights: General reviewers <?= $n((float) $weights['w_general'], 0) ?>% · Representatives <?= $n((float) $weights['w_representative'], 0) ?>% · Judges <?= $n((float) $weights['w_judge'], 0) ?>%.
        Open a presenter to see the three groups' votes.
    </span>
    <a class="btn btn-sm ms-auto" href="<?= site_url('admin/slams/' . (int) $slam['id'] . '/marks') ?>"><?= icon('nav.marks') ?>Full calculation</a>
</div>

<?php if ($rows === []): ?>
    <div class="empty"><p class="empty-title">No performances yet</p><p class="empty-subtitle text-secondary">Scores appear here as soon as performances have been reviewed.</p></div>
<?php else: ?>
<div class="row row-cards align-items-start">
    <?php foreach ($rows as $r): $b = $r['breakdown']; $top = $r['rank'] !== null && (int) $r['rank'] === 1 && $ranked > 1; ?>
        <div class="col-12 col-md-6 col-xl-4">
            <details class="card card-accent <?= $top ? 'accent-green' : 'accent-blue' ?> score-card">
                <summary class="card-body d-flex align-items-center gap-3" style="cursor:pointer;list-style:none">
                    <?php if (! empty($r['photo'])): ?>
                        <img class="avatar avatar-lg avatar-photo" src="<?= base_url($r['photo']) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <span class="avatar avatar-lg"><?= icon('person.user') ?></span>
                    <?php endif ?>
                    <div class="flex-fill min-w-0">
                        <div class="fw-bold ml-text text-truncate"><?= esc($r['presenter_name']) ?></div>
                        <div class="small text-secondary ml-text text-truncate"><?= esc($r['topic']) ?></div>
                        <span class="badge <?= $top ? 'bg-green-lt' : 'bg-secondary-lt' ?> mt-1"><?= $r['rank'] !== null ? '#' . (int) $r['rank'] : 'No rank yet' ?></span>
                    </div>
                    <div class="text-end">
                        <div class="display-6 lh-1"><?= $n($b['final']) ?></div>
                        <div class="small text-secondary">out of 10</div>
                    </div>
                </summary>
                <div class="list-group list-group-flush border-top">
                    <?php foreach ($b['groups'] as $g => $x): $counts = $x['share'] !== null; ?>
                        <div class="list-group-item <?= $counts ? '' : 'text-secondary' ?>">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-<?= $tone[$g] ?>-lt"><?= esc($x['label']) ?></span>
                                <span class="small ms-auto"><?= (int) ($r[$count[$g]] ?? 0) ?> vote<?= (int) ($r[$count[$g]] ?? 0) === 1 ? '' : 's' ?></span>
                                <span class="fw-bold"><?= $n($x['avg'] === null ? null : (float) $x['avg']) ?></span><span class="small text-secondary">/10</span>
                            </div>
                            <div class="small text-secondary mt-1">
                                <?php if ($counts): ?>
                                    Weight <?= $n($x['weight'], 0) ?>% → share <?= $n($x['share'] * 100, 1) ?>% → <strong class="text-body"><?= $n($x['contribution']) ?></strong> points
                                <?php else: ?>
                                    <?= $x['avg'] === null ? 'No votes from this group; its weight goes to the others.' : 'Weight 0%, not counted.' ?>
                                <?php endif ?>
                            </div>
                            <?php foreach ($detail($r, $g) as [$qt, $avg, $max]): ?>
                                <div class="d-flex justify-content-between small ml-text"><span class="text-truncate me-2"><?= esc($qt) ?></span><span><?= number_format($avg, 1) ?>/<?= $max ?></span></div>
                            <?php endforeach ?>
                        </div>
                    <?php endforeach ?>
                    <div class="list-group-item d-flex justify-content-between fw-bold"><span>Final mark</span><span><?= $n($b['final']) ?> / 10</span></div>
                </div>
            </details>
        </div>
    <?php endforeach ?>
</div>
<?php endif ?>
<?= $this->endSection() ?>
