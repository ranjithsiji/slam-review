<?php
/** @var array $lr late_request: name, mobile (masked), code, slam_name */
$this->setVar('narrow', true); $this->setVar('entry', 'frontend/entries/checkin.js'); $this->setVar('title', 'Waiting for the volunteer · Science Slam');
?>
<?= $this->extend('Modules\Core\Views\layouts\public') ?>
<?= $this->section('content') ?>
<div class="card card-md text-center" data-late-wait="<?= site_url('api/checkin/waiting') ?>" data-late-review="<?= site_url('review') ?>" data-late-restart="<?= site_url('checkin') ?>">
    <div class="card-body">
        <div class="late-wait-spinner mx-auto mb-3" aria-hidden="true"></div>
        <h2 class="h2 mb-1" data-late-title>Waiting for a volunteer</h2>
        <p class="text-secondary mb-3"><?= esc($lr['slam_name']) ?></p>
        <dl class="row text-start mx-auto mb-3" style="max-width:20rem">
            <dt class="col-5 text-secondary fw-normal">Name</dt><dd class="col-7 fw-bold fs-3 ml-text mb-2"><?= esc($lr['name']) ?></dd>
            <dt class="col-5 text-secondary fw-normal">Mobile</dt><dd class="col-7 fw-bold fs-3 mb-0" style="letter-spacing:.05em"><?= esc($lr['mobile']) ?></dd>
        </dl>
        <div class="late-code-box mx-auto mb-3">
            <div class="small text-secondary">Your code</div>
            <div class="late-code" aria-label="Your code is <?= esc(implode(' ', str_split($lr['code'])), 'attr') ?>"><?= esc($lr['code']) ?></div>
        </div>
        <div class="alert alert-info text-start mb-0" role="status" aria-live="polite" data-late-message>
            <strong>The gates are closed.</strong> Go to the volunteer desk and show your badge. Tell them the code above.
            <div class="mt-1">Keep this page open: it moves on by itself as soon as you are checked in.</div>
        </div>
    </div>
    <div class="card-footer"><a href="<?= site_url('checkin') ?>" class="text-secondary small">Not you? Start again</a></div>
</div>
<?= $this->endSection() ?>
