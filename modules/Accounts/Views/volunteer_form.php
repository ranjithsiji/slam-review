<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('content') ?>
<div class="row justify-content-center"><div class="col-12 col-lg-6">
<form class="card" method="post" action="<?= site_url($vol ? 'admin/volunteers/' . $vol['id'] : 'admin/volunteers/create') ?>">
    <?= csrf_field() ?>
    <div class="card-header"><h3 class="card-title"><?= esc($title) ?></h3></div>
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label required" for="name">Name</label>
            <input id="name" name="name" class="form-control<?= is_invalid($errors, 'name') ?>" required maxlength="120" value="<?= esc(old('name', $vol['name'] ?? '')) ?>">
            <?= field_error($errors, 'name') ?>
        </div>
        <div class="mb-3">
            <label class="form-label required" for="username">Username</label>
            <input id="username" name="username" class="form-control<?= is_invalid($errors, 'username') ?>" required autocapitalize="none" value="<?= esc(old('username', $vol['username'] ?? '')) ?>">
            <?= field_error($errors, 'username') ?>
        </div>
        <div class="mb-3">
            <label class="form-label required" for="slam_id">Slam</label>
            <select id="slam_id" name="slam_id" class="form-select<?= is_invalid($errors, 'slam_id') ?>" required>
                <option value="">Choose…</option>
                <?php foreach ($slams as $s): $sel = (int) old('slam_id', $vol['slam_id'] ?? $preselect) === (int) $s['id']; ?>
                    <option value="<?= $s['id'] ?>" <?= $sel ? 'selected' : '' ?>><?= esc($s['name']) ?></option>
                <?php endforeach ?>
            </select>
            <?= field_error($errors, 'slam_id') ?>
        </div>
        <div class="mb-3">
            <label class="form-label<?= $vol ? '' : ' required' ?>" for="password">Password</label>
            <input id="password" name="password" type="password" class="form-control<?= is_invalid($errors, 'password') ?>" autocomplete="new-password" <?= $vol ? '' : 'required' ?> minlength="10">
            <div class="form-hint"><?= $vol ? 'Leave blank to keep the current password.' : 'At least 10 characters.' ?></div>
            <?= field_error($errors, 'password') ?>
        </div>
        <label class="form-check form-switch">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" <?= (int) old('is_active', $vol['is_active'] ?? 1) ? 'checked' : '' ?>>
            <span class="form-check-label">Active</span>
        </label>
    </div>
    <div class="card-footer text-end">
        <a href="<?= site_url('admin/volunteers') ?>" class="btn btn-link">Cancel</a>
        <button class="btn btn-primary" type="submit"><?= icon('action.save') ?>Save</button>
    </div>
</form>
</div></div>
<?= $this->endSection() ?>
