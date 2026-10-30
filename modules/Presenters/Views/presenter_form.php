<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('content') ?>
<div class="row row-cards justify-content-center">
<div class="col-12 col-lg-7">
<form class="card" method="post" action="<?= site_url('admin/presenters/' . $p['id']) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="card-header"><h3 class="card-title">Presenter</h3></div>
    <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
            <?php if ($p['photo_thumb_path']): ?>
                <img src="<?= base_url($p['photo_thumb_path']) ?>" srcset="<?= base_url($p['photo_thumb_path']) ?> 200w, <?= base_url($p['photo_path']) ?> 800w" sizes="96px" alt="<?= esc($p['photo_alt'] ?: $p['name'], 'attr') ?>" class="presenter-photo">
            <?php else: ?>
                <span class="avatar avatar-xl"><?= icon('person.user', 'icon-lg') ?></span>
            <?php endif ?>
            <div class="flex-fill">
                <label class="form-label" for="photo"><?= $p['photo_path'] ? 'Replace photo' : 'Photo' ?></label>
                <input id="photo" name="photo" type="file" class="form-control" accept="image/jpeg,image/png,image/webp">
            </div>
        </div>
        <div class="mb-3"><label class="form-label required" for="name">Name</label><input id="name" name="name" class="form-control<?= is_invalid($errors, 'name') ?>" required value="<?= esc(old('name', $p['name'])) ?>"><?= field_error($errors, 'name') ?></div>
        <div class="mb-3"><label class="form-label" for="designation_or_institution">Designation / institution</label><input id="designation_or_institution" name="designation_or_institution" class="form-control" value="<?= esc(old('designation_or_institution', (string) $p['designation_or_institution'])) ?>"></div>
        <div class="mb-3"><label class="form-label" for="profile">Profile</label><textarea id="profile" name="profile" class="form-control<?= is_invalid($errors, 'profile') ?>" rows="4" data-maxlength="1000"><?= esc(old('profile', (string) $p['profile'])) ?></textarea><?= field_error($errors, 'profile') ?></div>
        <div class="mb-0"><label class="form-label" for="photo_alt">Photo description (alt text)</label><input id="photo_alt" name="photo_alt" class="form-control" value="<?= esc(old('photo_alt', (string) $p['photo_alt'])) ?>"></div>
    </div>
    <div class="card-footer d-flex gap-2">
        <button class="btn btn-primary ms-auto" type="submit"><?= icon('action.save') ?>Save</button>
    </div>
</form>
<?php if ($p['photo_path']): ?>
<form method="post" action="<?= site_url('admin/presenters/' . $p['id'] . '/remove-photo') ?>" class="mt-2 text-end" data-confirm="Remove the photo? A neutral placeholder will be shown.">
    <?= csrf_field() ?><button class="btn btn-link text-danger" type="submit">Remove photo</button>
</form>
<?php endif ?>
</div>
<div class="col-12 col-lg-5">
    <div class="card">
        <div class="card-header"><h3 class="card-title">Performances</h3></div>
        <div class="list-group list-group-flush">
            <?php foreach ($performances as $perf): ?>
                <a class="list-group-item list-group-item-action" href="<?= site_url('admin/performances/' . $perf['id'] . '/edit') ?>">
                    <div class="fw-bold ml-text"><?= esc($perf['topic']) ?></div><div class="text-secondary small"><?= esc($perf['slam_name']) ?></div>
                </a>
            <?php endforeach ?>
        </div>
    </div>
</div>
</div>
<?= $this->endSection() ?>
