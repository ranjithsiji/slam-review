<?php $auth = session('auth'); $path = '/' . trim(service('request')->getUri()->getPath(), '/'); ?>
<!doctype html>
<html lang="en">
<head>
    <?= view('Modules\Core\Views\partials\head') ?>
    <title><?= esc($title ?? 'Volunteer') ?> · Science Slam</title>
    <?= vite_tags($entry ?? 'frontend/entries/admin.js') ?>
</head>
<body class="vol-body">
<div class="page">
    <?php $links = [
        ['volunteer', 'nav.checkins', 'Check-in'], ['volunteer/not-reviewing', 'status.warning', 'Not reviewing'],
        ['volunteer/run-sheet', 'nav.schedule', 'Run-sheet'], ['volunteer/activity', 'nav.log', 'Activity'],
    ]; ?>
    <header class="vol-top d-print-none">
        <div class="container-xl vol-top-inner">
            <a class="vol-brand" href="<?= site_url('volunteer') ?>"><?= brand_logo('brand-logo-nav') ?>
                <span class="vol-brand-text"><span class="fw-bold">Volunteer desk</span><span class="small text-secondary text-truncate"><?= esc($auth['name'] ?? '') ?></span></span></a>
            <nav class="vol-nav" aria-label="Volunteer pages">
                <?php foreach ($links as [$url, $ic, $label]): $on = $path === '/' . $url; ?>
                    <a class="vol-nav-link<?= $on ? ' active' : '' ?>" href="<?= site_url($url) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= icon($ic) ?><span><?= $label ?></span></a>
                <?php endforeach ?>
            </nav>
            <div class="vol-actions">
                <?= theme_toggle('nav-link px-2') ?>
                <form method="post" action="<?= site_url('logout') ?>"><?= csrf_field() ?>
                    <button class="btn btn-outline-danger vol-logout" type="submit"><?= icon('action.logout', 'me-1') ?>Sign out</button>
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
