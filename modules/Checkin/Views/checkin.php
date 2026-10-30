<?php $this->setVar('narrow', true); $this->setVar('entry', 'frontend/entries/checkin.js'); $this->setVar('title', 'Check in · Science Slam'); ?>
<?= $this->extend('Modules\Core\Views\layouts\public') ?>
<?= $this->section('content') ?>
<div class="text-center mb-3">
    <?= brand_logo('brand-logo-card') ?>
</div>
<?php if ($msg = session('thanks')): ?><div class="alert alert-success"><?= esc($msg) ?></div><?php endif ?>
<?php if (! empty($pending)): ?>
<form class="card card-md" method="post" action="<?= site_url('checkin/confirm') ?>" data-once autocomplete="off">
    <?= csrf_field() ?>
    <?= honeypot_fields() ?>
    <div class="card-body text-center">
        <?= icon('person.badge', 'icon-xl text-primary mb-2') ?>
        <h2 class="h2 mb-1">Is this you?</h2>
        <p class="text-secondary mb-3"><?= esc($pending['slam_name']) ?></p>
        <dl class="row text-start mx-auto mb-3" style="max-width:20rem">
            <dt class="col-5 text-secondary fw-normal">Name</dt><dd class="col-7 fw-bold fs-3 ml-text mb-2"><?= esc($pending['name']) ?></dd>
            <dt class="col-5 text-secondary fw-normal">Mobile</dt><dd class="col-7 fw-bold fs-3 mb-2" style="letter-spacing:.05em"><?= esc($pending['mobile']) ?></dd>
            <dt class="col-5 text-secondary fw-normal">Badge ID</dt><dd class="col-7 mb-0"><code><?= esc($pending['badge_id']) ?></code><?= $pending['user_type'] === 'judge' ? ' · Judge' : '' ?></dd>
        </dl>
        <button class="btn btn-primary btn-lg w-100" type="submit"><?= icon('status.ok') ?>Yes, that's me, check me in</button>
        <a class="btn btn-link w-100 mt-2" href="<?= site_url('checkin/not-me') ?>">No, this is not me</a>
    </div>
</form>
<?php else: ?>
<form class="card card-md" method="post" action="<?= site_url('checkin') ?>" data-once autocomplete="off">
    <?= csrf_field() ?>
    <?= honeypot_fields() ?>
    <div class="card-body">
        <?php if ($badge): ?>
            <div class="text-center">
                <?= icon('person.badge', 'icon-xl text-primary mb-2') ?>
                <h2 class="h2 mb-1">Ready to check in?</h2>
                <p class="text-secondary">Your badge ID</p>
                <div class="display-6 fw-bold mb-3" style="letter-spacing:.15em"><?= esc($badge) ?></div>
                <input type="hidden" name="badge_id" value="<?= esc($badge) ?>">
                <button class="btn btn-primary btn-lg w-100" type="submit"><?= icon('nav.checkins') ?>Continue to check in</button>
                <p class="small text-secondary mt-2 mb-0">Next you will see your name and mobile number to confirm.</p>
                <p class="small text-secondary mt-3 mb-0">Not you? <a href="<?= site_url('checkin') ?>">Type a different ID</a></p>
            </div>
        <?php else: ?>
            <h2 class="h2 text-center mb-1">Check in</h2>
            <p class="text-center text-secondary">Type the ID printed on your badge</p>
            <label class="form-label visually-hidden" for="badge_id">Badge ID</label>
            <input id="badge_id" name="badge_id" class="form-control form-control-lg text-center fw-bold" style="font-size:1.75rem;letter-spacing:.2em"
                   placeholder="KR48213" maxlength="7" required autocapitalize="characters" autocorrect="off" spellcheck="false" inputmode="text"
                   pattern="[A-Za-z]{2}[0-9]{5}" aria-describedby="fmt" data-badge-input value="<?= esc((string) old('badge_id')) ?>">
            <div id="fmt" class="form-hint text-center mt-2">2 letters + 5 digits, for example KR48213</div>
            <button class="btn btn-primary btn-lg w-100 mt-3" type="submit"><?= icon('nav.checkins') ?>Continue</button>
            <div class="hr-text my-3">or</div>
            <!-- Scan the QR code on the badge with the phone camera (works only over https or localhost). -->
            <button type="button" class="btn btn-lg w-100" data-scan-start><?= icon('qr.scan') ?>Scan the QR code on my badge</button>
            <div data-scan-box hidden class="mt-3">
                <div id="qr-reader" class="rounded overflow-hidden mx-auto" style="max-width:360px"></div>
                <div class="text-center small text-secondary mt-2" data-scan-hint>Starting the camera… allow access if your phone asks.</div>
                <button type="button" class="btn w-100 mt-2" data-scan-stop>Stop camera</button>
            </div>
            <div class="alert alert-warning mt-3 mb-0" data-scan-error role="alert" hidden></div>
        <?php endif ?>
    </div>
</form>
<?php endif ?>
<p class="text-center small text-secondary mt-3">Check-in works only while the gates are open. Lost your badge or arrived late? Visit the volunteer desk.</p>
<p class="text-center small mb-1"><a href="<?= site_url('badge') ?>"><?= icon('qr.scan', 'icon-inline me-1') ?>Lost your badge? Get it here with your unique ID</a></p>
<p class="text-center small mb-1"><a href="<?= site_url('registered') ?>"><?= icon('action.search', 'icon-inline me-1') ?>Not sure you are registered? Check with your mobile number</a></p>
<p class="text-center small"><a href="<?= site_url('/') ?>" class="text-secondary">← Back to home</a></p>
<?= $this->endSection() ?>
