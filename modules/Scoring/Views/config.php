<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'marks']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $jt = old('judge_timing', $judging['timing']); $jm = old('judge_mode', $judging['mode']); $jl = $judgingLocked !== null; ?>
<form method="post" action="<?= site_url('admin/slams/' . $slam['id'] . '/judging') ?>" class="card mb-3 card-accent accent-purple">
    <?= csrf_field() ?>
    <div class="card-header"><h3 class="card-title"><?= icon('person.judge', 'me-2') ?>Judges' marks</h3></div>
    <div class="card-body">
        <?php if ($jl): ?><div class="alert alert-info"><?= icon('status.lock', 'me-1') ?><?= esc($judgingLocked) ?></div><?php endif ?>
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <div class="form-label">When do judges mark?</div>
                <div class="form-selectgroup form-selectgroup-boxes d-flex flex-column gap-2">
                    <label class="form-selectgroup-item"><input type="radio" name="judge_timing" value="end_of_slam" class="form-selectgroup-input" <?= $jt === 'end_of_slam' ? 'checked' : '' ?> <?= $jl ? 'disabled' : '' ?>>
                        <span class="form-selectgroup-label d-flex text-start p-3"><span><strong>At the end of the slam</strong><span class="d-block small text-secondary">Judges mark on paper; you enter each judge's consolidated mark out of 10 per performance on the <em>Judges' marks</em> page after the reviews close.</span></span></span></label>
                    <label class="form-selectgroup-item"><input type="radio" name="judge_timing" value="live" class="form-selectgroup-input" <?= $jt === 'live' ? 'checked' : '' ?> <?= $jl ? 'disabled' : '' ?>>
                        <span class="form-selectgroup-label d-flex text-start p-3"><span><strong>Live, on their phones</strong><span class="d-block small text-secondary">Judges check in with their badge and mark each performance while its review window is open.</span></span></span></label>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="form-label">Live marking mode</div>
                <div class="form-selectgroup form-selectgroup-boxes d-flex flex-column gap-2">
                    <label class="form-selectgroup-item"><input type="radio" name="judge_mode" value="detailed" class="form-selectgroup-input" <?= $jm === 'detailed' ? 'checked' : '' ?> <?= $jl ? 'disabled' : '' ?>>
                        <span class="form-selectgroup-label d-flex text-start p-3"><span><strong>Detailed</strong><span class="d-block small text-secondary">One mark per criterion: <?= esc(implode(', ', array_map(static fn ($q) => $q['text'] . ' ' . (int) $q['max_score'], $questions['judge']))) ?: 'the judge criteria' ?>.</span></span></span></label>
                    <label class="form-selectgroup-item"><input type="radio" name="judge_mode" value="consolidated" class="form-selectgroup-input" <?= $jm === 'consolidated' ? 'checked' : '' ?> <?= $jl ? 'disabled' : '' ?>>
                        <span class="form-selectgroup-label d-flex text-start p-3"><span><strong>Consolidated</strong><span class="d-block small text-secondary">One overall mark out of 10 per performance.</span></span></span></label>
                </div>
                <div class="form-hint mt-1">Used only when judges mark live. Judges may give one decimal (e.g. 2.5); no 0.</div>
            </div>
            <?php if (! $jl && $otherSlams !== []): ?>
                <div class="col-12">
                    <div class="form-label">Also apply to</div>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach ($otherSlams as $o): ?><label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="apply_to[]" value="<?= (int) $o['id'] ?>"><span class="form-check-label ml-text"><?= esc($o['name']) ?></span></label><?php endforeach ?>
                    </div>
                    <div class="form-hint">Only slams where reviewing has not started are listed.</div>
                </div>
            <?php endif ?>
        </div>
    </div>
    <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
        <?php if ($judging['timing'] === 'end_of_slam'): ?><a href="<?= site_url('admin/slams/' . $slam['id'] . '/judge-marks') ?>" class="me-auto"><?= icon('person.judge', 'icon-inline me-1') ?>Enter judges' marks</a><?php else: ?><span class="me-auto"></span><?php endif ?>
        <button class="btn btn-primary" type="submit" <?= $jl ? 'disabled' : '' ?>><?= icon('action.save') ?>Save judges' marking</button>
    </div>
</form>
<form method="post" action="<?= site_url('admin/slams/' . $slam['id'] . '/mark-config') ?>" class="row row-cards">
    <?= csrf_field() ?>
    <div class="col-12 col-lg-5">
        <div class="card card-accent accent-orange">
            <div class="card-header"><h3 class="card-title">Group weights (must add up to 100)</h3></div>
            <div class="card-body">
                <?php foreach ([
                    ['w_general', 'General reviewers', 'Audience users who are not representatives.'],
                    ['w_representative', 'Audience representatives', 'Drawn at random from the audience users who reviewed every performance, then edited by the admin.'],
                    ['w_judge', 'Judges', 'Their own question set.'],
                ] as [$f, $label, $hint]): ?>
                    <div class="mb-3">
                        <label class="form-label mb-0" for="<?= $f ?>"><?= esc($label) ?> (%)</label>
                        <div class="form-hint mt-0 mb-1"><?= esc($hint) ?></div>
                        <input id="<?= $f ?>" name="<?= $f ?>" type="number" step="0.5" min="0" max="100" class="form-control<?= is_invalid($errors, $f) ?>" value="<?= esc(old($f, (string) (float) ($cfg[$f] ?? 0))) ?>" <?= $slam['status'] === 'ended' ? 'disabled' : '' ?>>
                        <?= field_error($errors, $f) ?>
                    </div>
                <?php endforeach ?>
                <p class="small text-secondary mb-0">Final = Σ(group average × weight) ÷ Σ weights of the groups that have reviews. <a href="<?= site_url('admin/slams/' . $slam['id'] . '/representatives') ?>">Choose representatives</a></p>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-7">
        <div class="card card-accent accent-azure">
            <div class="card-header"><h3 class="card-title">Question weights</h3></div>
            <div class="card-body">
                <?php foreach (['user' => 'Audience (general reviewers and representatives)', 'judge' => $judging['consolidated'] ? 'Judge criteria (not used: judges give one overall mark)' : 'Judge criteria'] as $aud => $label): ?>
                    <h4><?= $label ?> <?= $locked[$aud] ? '<span class="badge bg-secondary-lt">' . icon('status.lock', 'icon-inline me-1') . 'locked</span>' : '' ?></h4>
                    <?php foreach ($questions[$aud] as $q): ?>
                        <div class="row g-2 align-items-center mb-2">
                            <div class="col-8 col-md-9"><span class="ml-text"><?= esc($q['text']) ?></span> <span class="badge bg-secondary-lt"><?= (int) $q['max_score'] ?> <?= (int) $q['max_score'] === 1 ? 'mark' : 'marks' ?></span></div>
                            <div class="col-4 col-md-3"><input type="number" step="0.25" min="0.25" max="100" name="qweight[<?= (int) $q['id'] ?>]" class="form-control" value="<?= esc((string) (float) $q['weight']) ?>" aria-label="Weight" <?= $locked[$aud] ? 'disabled' : '' ?>></div>
                        </div>
                    <?php endforeach ?>
                <?php endforeach ?>
                <p class="small text-secondary mb-0">Per-review mark (out of 10) = 10 × Σ(weight × marks) ÷ Σ(weight × maximum); with weight 1 the marks simply add up. Maximum marks are set on the <a href="<?= site_url('admin/slams/' . $slam['id'] . '/questions') ?>">Questions</a> page. Weights and maximums lock once the first review of that audience is submitted.</p>
            </div>
        </div>
    </div>
    <div class="col-12 sticky-actions text-end"><button class="btn btn-primary" type="submit" <?= $slam['status'] === 'ended' ? 'disabled' : '' ?>><?= icon('action.save') ?>Save</button></div>
</form>
<?= $this->endSection() ?>
