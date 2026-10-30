<?php
/** Reset-password card + the one-time new password. Vars: $action (POST url), $errors. */
$new = session('new_password');
?>
<?php if ($new): ?>
<div class="alert alert-warning" role="alert">
    <div class="fw-bold mb-1"><?= icon('status.lock', 'me-1') ?>New password: shown only now</div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <code class="fs-3 user-select-all"><?= esc($new) ?></code>
    </div>
    <div class="small mt-1">Hand it over privately. It is not stored in readable form and cannot be shown again; reset again if it is lost.</div>
</div>
<?php endif ?>
<form class="card card-accent accent-orange mt-3" method="post" action="<?= esc($action) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <div class="card-header"><h3 class="card-title">Reset password</h3></div>
    <div class="card-body">
        <label class="form-label" for="reset-password">New password</label>
        <input id="reset-password" name="password" type="text" class="form-control<?= is_invalid($errors, 'password') ?>" autocomplete="new-password" minlength="10" autocapitalize="none" spellcheck="false" placeholder="Leave blank to generate one">
        <div class="form-hint">At least 10 characters. Any device signed in with the old password is signed out.</div>
        <?= field_error($errors, 'password') ?>
    </div>
    <div class="card-footer text-end"><button class="btn btn-warning" type="submit"><?= icon('status.lock') ?>Reset password</button></div>
</form>
