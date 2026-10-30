<?php $auth = session('auth'); $path = '/' . trim(service('request')->getUri()->getPath(), '/'); ?>
<!doctype html>
<html lang="en">
<head>
    <?= view('Modules\Core\Views\partials\head') ?>
    <title><?= esc($title ?? 'Volunteer') ?> · Science Slam</title>
    <?= vite_tags($entry ?? 'frontend/entries/admin.js') ?>
</head>
<body>
<div class="page">
    <header class="navbar navbar-expand d-print-none">
        <div class="container-xl">
            <span class="navbar-brand fw-bold"><?= brand_logo('brand-logo-nav me-2') ?><span class="d-none d-sm-inline">Volunteer desk</span></span>
            <ul class="navbar-nav ms-2 overflow-auto">
                <li class="nav-item<?= $path === '/volunteer' ? ' active' : '' ?>"><a class="nav-link" href="<?= site_url('volunteer') ?>"><?= icon('nav.checkins', 'me-1') ?>Check-in</a></li>
                <li class="nav-item<?= $path === '/volunteer/not-reviewing' ? ' active' : '' ?>"><a class="nav-link" href="<?= site_url('volunteer/not-reviewing') ?>"><?= icon('status.warning', 'me-1') ?>Not reviewing</a></li>
                <li class="nav-item<?= $path === '/volunteer/run-sheet' ? ' active' : '' ?>"><a class="nav-link" href="<?= site_url('volunteer/run-sheet') ?>"><?= icon('nav.schedule', 'me-1') ?>Run-sheet</a></li>
                <li class="nav-item<?= $path === '/volunteer/activity' ? ' active' : '' ?>"><a class="nav-link" href="<?= site_url('volunteer/activity') ?>"><?= icon('nav.log', 'me-1') ?>Activity</a></li>
            </ul>
            <div class="navbar-nav flex-row ms-auto">
                <?= theme_toggle('nav-link px-2') ?>
                <form method="post" action="<?= site_url('logout') ?>" class="ms-1"><?= csrf_field() ?>
                    <button class="btn btn-ghost-secondary btn-icon" type="submit" aria-label="Sign out" title="Sign out (<?= esc($auth['name'] ?? '') ?>)"><?= icon('action.logout') ?></button>
                </form>
            </div>
        </div>
    </header>
    <div class="page-wrapper">
        <?= $this->renderSection('header') ?>
        <div class="page-body">
            <div class="container-xl">
                <?= view('Modules\Core\Views\partials\flash') ?>
                <?= $this->renderSection('content') ?>
            </div>
        </div>
    </div>
</div>
<?= $this->renderSection('boot') ?>
</body>
</html>
