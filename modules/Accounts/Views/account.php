<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('content') ?>
<div class="row row-cards justify-content-center"><div class="col-12 col-lg-6">
<form class="card card-accent accent-blue mb-3" method="post" action="<?= site_url('admin/account') ?>">
    <?= csrf_field() ?>
    <div class="card-header"><h3 class="card-title">My details</h3></div>
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label">Username</label>
            <input class="form-control" value="<?= esc($row['username']) ?>" disabled>
            <div class="form-hint">Only another administrator can change the username.</div>
        </div>
        <div class="mb-3">
            <label class="form-label required" for="name">Name</label>
            <input id="name" name="name" class="form-control<?= is_invalid($errors, 'name') ?>" required maxlength="120" value="<?= esc(old('name', $row['name'])) ?>">
            <?= field_error($errors, 'name') ?>
        </div>
        <div class="row g-3">
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
    </div>
    <div class="card-footer text-end"><button class="btn btn-primary" type="submit"><?= icon('action.save') ?>Save details</button></div>
</form>

<form class="card card-accent accent-orange" method="post" action="<?= site_url('admin/account/password') ?>" autocomplete="off">
    <?= csrf_field() ?>
    <div class="card-header"><h3 class="card-title">Change password</h3></div>
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label required" for="current">Current password</label>
            <input id="current" name="current" type="password" class="form-control<?= is_invalid($errors, 'current') ?>" autocomplete="current-password" required>
            <?= field_error($errors, 'current') ?>
        </div>
        <div class="mb-3">
            <label class="form-label required" for="password">New password</label>
            <input id="password" name="password" type="password" class="form-control<?= is_invalid($errors, 'password') ?>" autocomplete="new-password" required minlength="10">
            <div class="form-hint">At least 10 characters.</div>
            <?= field_error($errors, 'password') ?>
        </div>
        <div>
            <label class="form-label required" for="confirm">Repeat new password</label>
            <input id="confirm" name="confirm" type="password" class="form-control<?= is_invalid($errors, 'confirm') ?>" autocomplete="new-password" required minlength="10">
            <?= field_error($errors, 'confirm') ?>
        </div>
        <div class="small text-secondary mt-3">Other devices signed in as you are signed out when you change it.</div>
    </div>
    <div class="card-footer text-end"><button class="btn btn-warning" type="submit"><?= icon('status.lock') ?>Change password</button></div>
</form>
</div></div>
<?= $this->endSection() ?>
