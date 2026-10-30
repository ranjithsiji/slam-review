<?php $this->setVar('narrow', true); $this->setVar('entry', 'frontend/entries/checkin.js'); $this->setVar('title', 'Check in · Science Slam'); ?>
<?= $this->extend('Modules\Core\Views\layouts\public') ?>
<?= $this->section('content') ?>
<div class="text-center mb-3">
    <span class="navbar-brand fw-bold"><?= icon('status.live', 'text-primary me-1') ?>Science Slam</span>
</div>
<?php if ($msg = session('thanks')): ?><div class="alert alert-success"><?= esc($msg) ?></div><?php endif ?>
<form class="card card-md" method="post" action="<?= site_url('checkin') ?>" data-once autocomplete="off">
    <?= csrf_field() ?>
    <div class="card-body">
        <?php if ($badge): ?>
            <div class="text-center">
                <?= icon('person.badge', 'icon-xl text-primary mb-2') ?>
                <h2 class="h2 mb-1">Ready to check in?</h2>
                <p class="text-secondary">Your badge ID</p>
                <div class="display-6 fw-bold mb-3" style="letter-spacing:.15em"><?= esc($badge) ?></div>
                <input type="hidden" name="badge_id" value="<?= esc($badge) ?>">
                <button class="btn btn-primary btn-lg w-100" type="submit"><?= icon('nav.checkins') ?>Check in as <?= esc($badge) ?></button>
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
            <button class="btn btn-primary btn-lg w-100 mt-3" type="submit"><?= icon('nav.checkins') ?>Check in</button>
        <?php endif ?>
    </div>
</form>
<p class="text-center small text-secondary mt-3">Check-in works only while the gate is open. Lost your badge? Visit the volunteer desk.</p>
<div class="text-center"><button type="button" class="btn btn-ghost-secondary btn-sm" data-theme-toggle><?= icon('theme.dark', 'me-1') ?>Dark / light</button></div>
<?= $this->endSection() ?>
