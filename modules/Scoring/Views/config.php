<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'marks']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<form method="post" action="<?= site_url('admin/slams/' . $slam['id'] . '/mark-config') ?>" class="row row-cards">
    <?= csrf_field() ?>
    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Group weights (must add up to 100)</h3></div>
            <div class="card-body">
                <?php foreach ([['w_user', 'Audience users (A_u)'], ['w_judge', 'Judges (A_j)'], ['w_complete_reviewer', 'Complete reviewers (A_c)']] as [$f, $label]): ?>
                    <div class="mb-3">
                        <label class="form-label" for="<?= $f ?>"><?= esc($label) ?></label>
                        <input id="<?= $f ?>" name="<?= $f ?>" type="number" step="0.5" min="0" max="100" class="form-control<?= is_invalid($errors, $f) ?>" value="<?= esc(old($f, (string) (float) ($cfg[$f] ?? 0))) ?>" <?= $slam['status'] === 'ended' ? 'disabled' : '' ?>>
                        <?= field_error($errors, $f) ?>
                    </div>
                <?php endforeach ?>
                <p class="small text-secondary mb-0">Final = (w_u·A_u + w_j·A_j + w_c·A_c) / (sum of weights of groups that have votes). Complete reviewers are audience users who reviewed every opened performance.</p>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Question weights</h3></div>
            <div class="card-body">
                <?php foreach (['user' => 'Audience users', 'judge' => 'Judges'] as $aud => $label): ?>
                    <h4><?= $label ?> <?= $locked[$aud] ? '<span class="badge bg-secondary-lt">' . icon('status.lock', 'icon-inline me-1') . 'locked</span>' : '' ?></h4>
                    <?php foreach ($questions[$aud] as $q): ?>
                        <div class="row g-2 align-items-center mb-2">
                            <div class="col-8 col-md-9 ml-text"><?= esc($q['text']) ?></div>
                            <div class="col-4 col-md-3"><input type="number" step="0.25" min="0.25" max="100" name="qweight[<?= (int) $q['id'] ?>]" class="form-control" value="<?= esc((string) (float) $q['weight']) ?>" aria-label="Weight" <?= $locked[$aud] ? 'disabled' : '' ?>></div>
                        </div>
                    <?php endforeach ?>
                <?php endforeach ?>
                <p class="small text-secondary mb-0">Per-review mark = Σ(weight × score) / Σ(weight). Weights lock once the first review of that audience is submitted.</p>
            </div>
        </div>
    </div>
    <div class="col-12 sticky-actions text-end"><button class="btn btn-primary" type="submit" <?= $slam['status'] === 'ended' ? 'disabled' : '' ?>><?= icon('action.save') ?>Save</button></div>
</form>
<?= $this->endSection() ?>
