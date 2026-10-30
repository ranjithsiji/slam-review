<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'presenters']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<form id="presenter-form" method="post" action="<?= site_url('admin/slams/' . $slam['id'] . '/presenters') ?>" enctype="multipart/form-data" class="row row-cards">
    <?= csrf_field() ?>
    <div class="col-12 col-lg-6">
        <div class="card card-accent accent-indigo">
            <div class="card-header"><h3 class="card-title">Presenter</h3></div>
            <div class="card-body">
                <?php if ($pool !== []): ?>
                <div class="mb-3">
                    <label class="form-label" for="presenter_id">Existing presenter <span class="text-secondary">(e.g. a regional presenter for the final)</span></label>
                    <select id="presenter_id" name="presenter_id" class="form-select">
                        <option value="">— New presenter —</option>
                        <?php foreach ($pool as $p): ?><option value="<?= $p['id'] ?>" <?= (int) old('presenter_id') === (int) $p['id'] ? 'selected' : '' ?>><?= esc($p['name']) ?><?= $p['last_topic'] ? ' — ' . esc($p['last_topic']) : '' ?></option><?php endforeach ?>
                    </select>
                    <div class="form-hint">If you pick an existing presenter, the fields below for name, profile and photo are ignored.</div>
                </div>
                <?php endif ?>
                <div class="mb-3"><label class="form-label required" for="name">Name</label><input id="name" name="name" class="form-control<?= is_invalid($errors, 'name') ?>" maxlength="120" value="<?= esc(old('name')) ?>"><?= field_error($errors, 'name') ?></div>
                <div class="mb-3"><label class="form-label" for="designation_or_institution">Designation / institution</label><input id="designation_or_institution" name="designation_or_institution" class="form-control" maxlength="200" value="<?= esc(old('designation_or_institution')) ?>"></div>
                <div class="mb-3"><label class="form-label" for="profile">Profile (short bio)</label><textarea id="profile" name="profile" class="form-control<?= is_invalid($errors, 'profile') ?>" rows="3" data-maxlength="1000"><?= esc(old('profile')) ?></textarea><?= field_error($errors, 'profile') ?></div>
                <div class="mb-3">
                    <label class="form-label" for="photo">Photo</label>
                    <input id="photo" name="photo" type="file" class="form-control" accept="image/jpeg,image/png,image/webp">
                    <div class="form-hint">JPG, PNG or WebP, up to 3 MB. Camera or gallery.</div>
                </div>
                <div class="mb-0"><label class="form-label" for="photo_alt">Photo description (alt text)</label><input id="photo_alt" name="photo_alt" class="form-control" maxlength="200" value="<?= esc(old('photo_alt')) ?>"></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card mb-3 card-accent accent-green">
            <div class="card-header"><h3 class="card-title">Talk in this slam</h3></div>
            <div class="card-body">
                <div class="mb-3"><label class="form-label required" for="topic">Topic</label><input id="topic" name="topic" class="form-control<?= is_invalid($errors, 'topic') ?>" required maxlength="200" value="<?= esc(old('topic')) ?>"><?= field_error($errors, 'topic') ?></div>
                <div class="mb-0"><label class="form-label" for="abstract">Abstract</label><textarea id="abstract" name="abstract" class="form-control<?= is_invalid($errors, 'abstract') ?>" rows="5" data-maxlength="2000"><?= esc(old('abstract')) ?></textarea><?= field_error($errors, 'abstract') ?></div>
            </div>
        </div>
        <div class="alert alert-info mb-0">
            <div class="d-flex gap-2"><?= icon('status.info', 'flex-none') ?><div>
                <strong>No schedule time needed here.</strong> Presenters go into this slam's <em>pool</em> first. When the pool is ready, build the running order on the
                <a href="<?= site_url('admin/slams/' . $slam['id'] . '/schedule') ?>">Schedule</a> page, then publish it.
            </div></div>
        </div>
    </div>
    <div class="col-12 sticky-actions d-flex justify-content-end gap-2">
        <a href="<?= site_url('admin/slams/' . $slam['id'] . '/presenters') ?>" class="btn btn-link">Cancel</a>
        <button class="btn" type="submit" name="again" value="1"><?= icon('action.add') ?>Add &amp; add another</button>
        <button class="btn btn-primary" type="submit"><?= icon('action.save') ?>Add to pool</button>
    </div>
</form>
<?= $this->endSection() ?>
