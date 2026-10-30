<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'presenters']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row justify-content-center"><div class="col-12 col-lg-8">
<form class="card card-accent accent-blue" method="post" action="<?= site_url('admin/performances/' . $perf['id']) ?>">
    <?= csrf_field() ?>
    <div class="card-header"><h3 class="card-title"><?= esc($perf['presenter_name']) ?></h3></div>
    <div class="card-body">
        <?php if ($reviews > 0): ?><div class="alert alert-info"><?= icon('status.info', 'me-1') ?>This performance already has <?= $reviews ?> review(s). Edits are allowed and logged.</div><?php endif ?>
        <div class="mb-3"><label class="form-label required" for="topic">Topic</label><input id="topic" name="topic" class="form-control<?= is_invalid($errors, 'topic') ?>" required maxlength="200" value="<?= esc(old('topic', $perf['topic'])) ?>"><?= field_error($errors, 'topic') ?></div>
        <div class="mb-3"><label class="form-label" for="abstract">Abstract</label><textarea id="abstract" name="abstract" class="form-control<?= is_invalid($errors, 'abstract') ?>" rows="6" data-maxlength="2000"><?= esc(old('abstract', (string) $perf['abstract'])) ?></textarea><?= field_error($errors, 'abstract') ?></div>
        <label class="form-check form-switch">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" <?= (int) old('is_active', $perf['is_active']) ? 'checked' : '' ?>>
            <span class="form-check-label">Active <span class="text-secondary">(deactivating skips its pending slot; history and marks stay intact)</span></span>
        </label>
    </div>
    <div class="card-footer text-end">
        <a class="btn btn-link" href="<?= site_url('admin/slams/' . $slam['id'] . '/presenters') ?>">Cancel</a>
        <button class="btn btn-primary" type="submit"><?= icon('action.save') ?>Save</button>
    </div>
</form>
</div></div>
<?= $this->endSection() ?>
