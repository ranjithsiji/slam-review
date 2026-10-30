<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'reviewers']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row row-cards">
<div class="col-12 col-lg-7">
<form class="card card-accent accent-blue" method="post" action="<?= site_url($a ? 'admin/attendees/' . $a['id'] : 'admin/slams/' . $slam['id'] . '/attendees') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="back" value="<?= esc($back ?? 'reviewers', 'attr') ?>">
    <div class="card-header"><h3 class="card-title"><?= ($a ? 'Edit ' : 'Add ') . (($a['user_type'] ?? $defaultType ?? 'user') === 'judge' ? 'judge' : 'reviewer') ?></h3></div>
    <div class="card-body">
        <p class="text-secondary small">Only pre-registered people can check in. A badge ID (2 letters + 5 digits) is generated automatically.</p>
        <div class="mb-3"><label class="form-label required" for="name">Name</label><input id="name" name="name" class="form-control<?= is_invalid($errors, 'name') ?>" required maxlength="120" value="<?= esc(old('name', $a['name'] ?? '')) ?>"><?= field_error($errors, 'name') ?></div>
        <div class="row">
            <div class="col-12 col-sm-6 mb-3"><label class="form-label required" for="mobile">Mobile</label><input id="mobile" name="mobile" type="tel" inputmode="tel" class="form-control<?= is_invalid($errors, 'mobile') ?>" required value="<?= esc(old('mobile', $a['mobile'] ?? '')) ?>"><?= field_error($errors, 'mobile') ?></div>
            <div class="col-12 col-sm-6 mb-3"><label class="form-label" for="email">Email</label><input id="email" name="email" type="email" class="form-control<?= is_invalid($errors, 'email') ?>" value="<?= esc(old('email', $a['email'] ?? '')) ?>"><?= field_error($errors, 'email') ?></div>
        </div>
        <?php if ($a): ?>
        <div class="mb-3">
            <label class="form-label">Role</label>
            <div class="btn-group w-100" role="group">
                <?php foreach (['user' => 'Reviewer', 'judge' => 'Judge'] as $k => $l): ?>
                    <input type="radio" class="btn-check" name="user_type" id="t-<?= $k ?>" value="<?= $k ?>" <?= old('user_type', $a['user_type']) === $k ? 'checked' : '' ?>>
                    <label for="t-<?= $k ?>" class="btn"><?= icon($k === 'judge' ? 'person.judge' : 'person.user') ?><?= $l ?></label>
                <?php endforeach ?>
            </div>
            <div class="form-hint">Everyone in the audience is a reviewer. Only judges are different: they have their own question set and screen.</div>
        </div>
        <?php else: ?>
        <input type="hidden" name="user_type" value="<?= esc($defaultType ?? 'user', 'attr') ?>">
        <?php endif ?>
        <?php if ($a): ?>
        <label class="form-check form-switch">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" <?= (int) old('is_active', $a['is_active']) ? 'checked' : '' ?>>
            <span class="form-check-label">Active (deactivating ends any open session)</span>
        </label>
        <?php endif ?>
    </div>
    <div class="card-footer text-end">
        <a class="btn btn-link" href="<?= site_url('admin/slams/' . $slam['id'] . '/' . (in_array($back ?? 'reviewers', ['reviewers', 'judges'], true) ? $back : 'reviewers')) ?>">Cancel</a>
        <button class="btn btn-primary" type="submit"><?= icon('action.save') ?>Save</button>
    </div>
</form>
</div>
<?php if ($a): ?>
<div class="col-12 col-lg-5">
    <div class="card mb-3">
        <div class="card-body text-center">
            <div class="qr-box mb-2" style="max-width:220px"><img src="<?= site_url('admin/qr/' . $a['id'] . '.svg?inline=1') ?>" alt="QR code for <?= esc($a['badge_id'], 'attr') ?>"></div>
            <div class="h1 mb-0" style="letter-spacing:.15em"><?= esc($a['badge_id']) ?></div>
            <div class="mb-2"><?= status_badge('user_type', $a['user_type']) ?> <?= status_badge('checkin', $status['state']) ?></div>
            <div class="small text-secondary mb-2">Printed <?= (int) $a['badge_print_count'] ?>×<?= $a['badge_last_printed_at'] ? ', last ' . esc(local_time($a['badge_last_printed_at'], 'd M H:i')) : '' ?></div>
            <div class="btn-list justify-content-center">
                <a class="btn" href="<?= site_url('admin/qr/' . $a['id'] . '.svg') ?>"><?= icon('action.download') ?>SVG</a>
                <a class="btn" href="<?= site_url('admin/qr/' . $a['id'] . '.png') ?>"><?= icon('action.download') ?>PNG</a>
                <button class="btn" type="button" data-copy="<?= esc($qrUrl, 'attr') ?>"><?= icon('action.copy') ?>Link</button>
            </div>
        </div>
    </div>
    <form class="card card-accent accent-green" method="post" action="<?= site_url('admin/attendees/' . $a['id'] . '/reissue-id') ?>"
          data-confirm="Issue a new ID? The current ID stops working immediately and any open session ends. Print a new badge afterwards." data-reason="reason" data-danger data-ok="Reissue ID">
        <?= csrf_field() ?>
        <div class="card-body">
            <h3 class="card-title">Lost or exposed badge?</h3>
            <p class="text-secondary small">Reissuing gives this person a new ID. Reprinting an unchanged badge is also fine (it is counted).</p>
            <button class="btn btn-outline-danger" type="submit">Reissue ID</button>
        </div>
    </form>
</div>
<?php endif ?>
</div>
<?= $this->endSection() ?>
