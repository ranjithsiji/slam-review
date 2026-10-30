<?= $this->extend('Modules\Core\Views\layouts\public') ?>
<?= $this->section('content') ?>
<div class="text-center my-4">
    <h1 class="mb-2"><?= brand_logo('brand-logo-hero') ?><span class="visually-hidden">Kerala Science Slam</span></h1>
    <p class="text-secondary">Audience reviews, live at the venue.</p>
</div>
<div class="row g-3 justify-content-center">
    <div class="col-12 col-md-4">
        <a class="card card-link card-link-pop h-100" href="<?= site_url('checkin') ?>">
            <div class="card-body text-center py-4">
                <?= icon('qr.scan', 'icon-xl text-primary mb-2') ?>
                <h3 class="card-title mb-1">Check in to review</h3>
                <div class="text-secondary">Scan your badge or type your ID</div>
            </div>
        </a>
    </div>
    <div class="col-12 col-md-4">
        <a class="card card-link card-link-pop h-100" href="<?= site_url('slams') ?>">
            <div class="card-body text-center py-4">
                <?= icon('nav.schedule', 'icon-xl text-green mb-2') ?>
                <h3 class="card-title mb-1">Schedules</h3>
                <div class="text-secondary"><?= count($slams) ?> published slam<?= count($slams) === 1 ? '' : 's' ?></div>
            </div>
        </a>
    </div>
    <div class="col-12 col-md-4">
        <a class="card card-link card-link-pop h-100" href="<?= site_url('registered') ?>">
            <div class="card-body text-center py-4">
                <?= icon('person.badge', 'icon-xl text-orange mb-2') ?>
                <h3 class="card-title mb-1">Am I registered?</h3>
                <div class="text-secondary">Check with your mobile number</div>
            </div>
        </a>
    </div>
</div>
<div class="text-center mt-5 small">
    <a href="<?= site_url('volunteer/login') ?>" class="text-secondary me-3">Volunteer sign-in</a>
    <a href="<?= site_url('admin/login') ?>" class="text-secondary">Organiser sign-in</a>
</div>
<?= $this->endSection() ?>
