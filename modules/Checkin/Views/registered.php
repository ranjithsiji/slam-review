<?php $this->setVar('narrow', true); $this->setVar('entry', 'frontend/entries/checkin.js'); $this->setVar('title', 'Am I registered? · Science Slam'); ?>
<?= $this->extend('Modules\Core\Views\layouts\public') ?>
<?= $this->section('content') ?>
<div class="text-center mb-3"><a href="<?= site_url('/') ?>"><?= brand_logo('brand-logo-card') ?></a></div>
<form class="card card-md" method="post" action="<?= site_url('registered') ?>" autocomplete="off">
    <?= csrf_field() ?>
    <div class="card-body">
        <h2 class="h2 text-center mb-1">Am I registered?</h2>
        <p class="text-center text-secondary">Enter the mobile number you registered with.</p>
        <label class="form-label visually-hidden" for="mobile">Mobile number</label>
        <input id="mobile" name="mobile" type="tel" inputmode="numeric" autocomplete="tel-national" maxlength="15" required
               class="form-control form-control-lg text-center fw-bold<?= $error ? ' is-invalid' : '' ?>" style="font-size:1.5rem;letter-spacing:.1em"
               placeholder="98470 12345" value="<?= esc($mobile) ?>">
        <?php if ($error): ?><div class="invalid-feedback text-center"><?= esc($error) ?></div><?php endif ?>
        <button class="btn btn-primary btn-lg w-100 mt-3" type="submit"><?= icon('action.search') ?>Check</button>
    </div>
    <?php if (is_array($result)): ?>
        <div class="card-footer" role="status" aria-live="polite">
            <?php if ($result === []): ?>
                <div class="d-flex gap-2"><?= icon('status.warning', 'icon-lg text-orange') ?><div><div class="fw-bold">Not registered</div>
                    <div class="text-secondary small">This number is not registered for any upcoming slam. Only pre-registered people can check in; please contact the organisers.</div></div></div>
            <?php else: ?>
                <div class="d-flex gap-2"><?= icon('status.ok', 'icon-lg text-green') ?><div><div class="fw-bold">Yes, you are registered</div>
                    <ul class="list-unstyled small mb-1 mt-1">
                        <?php foreach ($result as $r): ?><li class="ml-text"><strong><?= esc($r['slam_name']) ?></strong> · <?= esc(local_time($r['opens_at'], 'D, d M Y')) ?> · <?= esc($r['venue_name']) ?><?= $r['user_type'] === 'judge' ? ' · as a judge' : '' ?></li><?php endforeach ?>
                    </ul>
                    <div class="text-secondary small">Your ID is printed on your badge. When the gates open, scan the QR code on it to check in. Lost your badge? Visit the volunteer desk.</div></div></div>
            <?php endif ?>
        </div>
    <?php endif ?>
</form>
<p class="text-center small mt-3 mb-1"><a href="<?= site_url('checkin') ?>"><?= icon('nav.checkins', 'icon-inline me-1') ?>Go to check-in</a></p>
<p class="text-center small"><a href="<?= site_url('/') ?>" class="text-secondary">← Back to home</a></p>
<?= $this->endSection() ?>
