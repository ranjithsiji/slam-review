<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('content') ?>
<div class="row justify-content-center"><div class="col-12 col-lg-6">
<form class="card card-accent accent-blue" method="post" action="<?= site_url($row ? 'admin/users/' . $row['id'] : 'admin/users/create') ?>">
    <?= csrf_field() ?>
    <div class="card-header"><h3 class="card-title"><?= esc($title) ?></h3></div>
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label required" for="name">Name</label>
            <input id="name" name="name" class="form-control<?= is_invalid($errors, 'name') ?>" required maxlength="120" value="<?= esc(old('name', $row['name'] ?? '')) ?>">
            <?= field_error($errors, 'name') ?>
        </div>
        <div class="mb-3">
            <label class="form-label required" for="username">Username</label>
            <input id="username" name="username" class="form-control<?= is_invalid($errors, 'username') ?>" required autocapitalize="none" value="<?= esc(old('username', $row['username'] ?? '')) ?>">
            <?= field_error($errors, 'username') ?>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-7">
                <label class="form-label" for="email">Email</label>
                <input id="email" name="email" type="email" class="form-control<?= is_invalid($errors, 'email') ?>" maxlength="160" autocapitalize="none" value="<?= esc(old('email', $row['email'] ?? '')) ?>">
                <?= field_error($errors, 'email') ?>
            </div>
            <div class="col-12 col-md-5">
                <label class="form-label" for="phone">Phone</label>
                <input id="phone" name="phone" type="tel" class="form-control<?= is_invalid($errors, 'phone') ?>" maxlength="20" value="<?= esc(old('phone', $row['phone'] ?? '')) ?>">
                <?= field_error($errors, 'phone') ?>
            </div>
        </div>
        <?php $role = old('role', $row['role'] ?? ($asOperator ? 'operator' : 'admin')); ?>
        <div class="mb-3">
            <label class="form-label required" for="role">Role</label>
            <select id="role" name="role" class="form-select<?= is_invalid($errors, 'role') ?>" <?= ! empty($isSelf) ? 'disabled' : '' ?> data-toggle-target="#operator-slam" data-toggle-value="operator">
                <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Administrator: all slams and settings</option>
                <option value="operator" <?= $role === 'operator' ? 'selected' : '' ?>>Slam operator: runs one slam</option>
            </select>
            <?php if (! empty($isSelf)): ?><input type="hidden" name="role" value="<?= esc($role) ?>"><?php endif ?>
            <?= field_error($errors, 'role') ?>
        </div>
        <div class="mb-3" id="operator-slam" <?= $role === 'operator' ? '' : 'hidden' ?>>
            <label class="form-label required" for="slam_id">Slam</label>
            <select id="slam_id" name="slam_id" class="form-select<?= is_invalid($errors, 'slam_id') ?>">
                <option value="">Choose…</option>
                <?php foreach ($slams as $s): $sel = (int) old('slam_id', $row['slam_id'] ?? $preselect) === (int) $s['id']; ?>
                    <option value="<?= $s['id'] ?>" <?= $sel ? 'selected' : '' ?>><?= esc($s['name']) ?></option>
                <?php endforeach ?>
            </select>
            <div class="form-hint">An operator prepares the slam (presenters, experts, participants, schedule, times, weights) and runs it on the day (controls, check-ins, marks, scores). They cannot see other slams, settings or other users.</div>
            <?= field_error($errors, 'slam_id') ?>
        </div>
        <?php if (! $row): ?>
        <div class="mb-3">
            <label class="form-label required" for="password">Password</label>
            <input id="password" name="password" type="password" class="form-control<?= is_invalid($errors, 'password') ?>" autocomplete="new-password" required minlength="10">
            <div class="form-hint">At least 10 characters. Share it privately; they can change it under My account.</div>
            <?= field_error($errors, 'password') ?>
        </div>
        <?php endif ?>
        <label class="form-check form-switch mb-0">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input<?= is_invalid($errors, 'is_active') ?>" type="checkbox" name="is_active" value="1" <?= (int) old('is_active', $row['is_active'] ?? 1) ? 'checked' : '' ?>>
            <span class="form-check-label">Active (can sign in)</span>
        </label>
        <?= field_error($errors, 'is_active') ?>
        <?php if ($row): ?><div class="small text-secondary mt-3">Last sign-in: <?= $row['last_login_at'] ? esc(local_time($row['last_login_at'], 'd M Y H:i')) : 'never' ?><?= $isSelf ? ' · this is your own account' : '' ?></div><?php endif ?>
    </div>
    <div class="card-footer text-end">
        <a href="<?= site_url('admin/users') ?>" class="btn btn-link">Cancel</a>
        <button class="btn btn-primary" type="submit"><?= icon('action.save') ?>Save</button>
    </div>
</form>
<?php if ($row): ?>
    <?= view('Modules\Accounts\Views\reset_card', ['action' => site_url("admin/users/{$row['id']}/reset-password"), 'errors' => $errors]) ?>
<?php endif ?>
</div></div>
<?= $this->endSection() ?>
