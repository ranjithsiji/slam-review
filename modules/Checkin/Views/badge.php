<?php
/** @var array|null $badge  @var string|null $qrSvg  @var string|null $error  @var string $typed */
$this->setVar('narrow', true); $this->setVar('entry', 'frontend/entries/checkin.js'); $this->setVar('title', 'Get my badge · Science Slam');
?>
<?= $this->extend('Modules\Core\Views\layouts\public') ?>
<?= $this->section('content') ?>
<?php if ($badge === null): ?>
<form class="card card-md" method="post" action="<?= site_url('badge') ?>" autocomplete="off">
    <?= csrf_field() ?>
    <?= honeypot_fields() ?>
    <div class="card-body">
        <h2 class="h2 text-center mb-1"><?= icon('qr.scan', 'me-2') ?>Get my badge</h2>
        <p class="text-center text-secondary">Lost or forgot your badge? Enter your unique ID (2 letters and 5 digits, like <strong>KR48213</strong>) to see your badge and download its QR code.</p>
        <label class="form-label visually-hidden" for="badge_id">Unique ID</label>
        <input id="badge_id" name="badge_id" class="form-control form-control-lg text-center fw-bold<?= $error ? ' is-invalid' : '' ?>" style="font-size:1.75rem;letter-spacing:.2em"
               required maxlength="7" autocapitalize="characters" autocomplete="off" spellcheck="false" inputmode="text" pattern="[A-Za-z]{2}[0-9]{5}" placeholder="KR48213" data-badge-input value="<?= esc($typed) ?>">
        <?php if ($error): ?><div class="invalid-feedback text-center"><?= esc($error) ?></div><?php endif ?>
        <button class="btn btn-slam btn-lg w-100 mt-3" type="submit"><?= icon('action.search') ?>Show my badge</button>
    </div>
</form>
<?php else: ?>
<div class="card card-md badge-card text-center">
    <div class="card-body">
        <div class="text-secondary small text-uppercase fw-bold mb-1"><?= esc($badge['slam_name']) ?></div>
        <div class="small text-secondary mb-3"><?= esc(local_time($badge['opens_at'], 'D, d M Y')) ?> · <?= esc($badge['venue_name']) ?></div>
        <h2 class="h2 ml-text mb-1"><?= esc($badge['name']) ?></h2>
        <span class="badge <?= $badge['user_type'] === 'judge' ? 'bg-purple-lt' : 'bg-blue-lt' ?> mb-3"><?= $badge['user_type'] === 'judge' ? 'JUDGE' : 'AUDIENCE' ?></span>
        <div class="badge-qr mx-auto" role="img" aria-label="QR code for <?= esc($badge['badge_id'], 'attr') ?>"><?= $qrSvg ?></div>
        <div class="badge-id mt-2"><?= esc($badge['badge_id']) ?></div>
        <p class="small text-secondary mt-2 mb-0">Show this QR code at the entrance, or scan it with your phone camera to check in when the gates open.</p>
    </div>
    <div class="card-footer d-grid gap-2">
        <a class="btn btn-slam" href="<?= site_url('badge/' . $badge['badge_id'] . '.pdf') ?>"><?= icon('action.export') ?>Download my badge (PDF, to print)</a>
        <div class="row g-2">
            <div class="col-6"><a class="btn w-100" href="<?= site_url('badge/' . $badge['badge_id'] . '.png') ?>"><?= icon('action.export') ?>QR code (PNG)</a></div>
            <div class="col-6"><a class="btn w-100" href="<?= site_url('badge/' . $badge['badge_id'] . '.svg') ?>"><?= icon('action.export') ?>QR code (SVG)</a></div>
        </div>
    </div>
</div>
<p class="text-center small mt-3"><a href="<?= site_url('badge') ?>" class="text-secondary">← Look up another ID</a> · <a href="<?= site_url('checkin') ?>" class="text-secondary">Go to check-in</a></p>
<?php endif ?>
<?= $this->endSection() ?>
