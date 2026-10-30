<?php $auth = session('auth'); ?>
<!doctype html>
<html lang="en">
<head>
    <?= view('Modules\Core\Views\partials\head') ?>
    <title><?= esc($title ?? 'Admin') ?> · Science Slam</title>
    <?= vite_tags($entry ?? 'frontend/entries/admin.js') ?>
</head>
<body>
<a class="visually-hidden-focusable" href="#main">Skip to content</a>
<div class="page">
    <header class="navbar navbar-expand-md d-print-none">
        <div class="container-xl">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-menu" aria-controls="navbar-menu" aria-expanded="false" aria-label="Menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <a class="navbar-brand navbar-brand-autodark pe-0 pe-md-3 fw-bold" href="<?= site_url('admin') ?>">
                <?= icon('status.live', 'text-primary me-1') ?>Science Slam
            </a>
            <div class="navbar-nav flex-row order-md-last">
                <button type="button" class="nav-link px-2 btn btn-ghost-secondary btn-icon" data-theme-toggle aria-label="Toggle dark mode" title="Toggle dark mode"><?= icon('theme.dark') ?></button>
                <div class="nav-item dropdown">
                    <a href="#" class="nav-link d-flex lh-1 text-reset p-0 ms-2" data-bs-toggle="dropdown" aria-label="Account menu">
                        <span class="avatar avatar-sm"><?= esc(mb_strtoupper(mb_substr($auth['name'] ?? '?', 0, 1))) ?></span>
                        <div class="d-none d-xl-block ps-2">
                            <div><?= esc($auth['name'] ?? '') ?></div>
                            <div class="mt-1 small text-secondary"><?= esc($auth['type'] ?? '') ?></div>
                        </div>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                        <form method="post" action="<?= site_url('logout') ?>"><?= csrf_field() ?>
                            <button class="dropdown-item" type="submit"><?= icon('action.logout', 'me-2') ?>Sign out</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="collapse navbar-collapse" id="navbar-menu">
                <ul class="navbar-nav">
                    <?php
                    $path = '/' . trim(service('request')->getUri()->getPath(), '/');
                    $nav  = [
                        ['admin', 'Dashboard', 'nav.dashboard'],
                        ['admin/slams', 'Slams', 'nav.slams'],
                        ['admin/presenters', 'Presenters', 'nav.presenters'],
                        ['admin/qr', 'Badges', 'nav.qr'],
                        ['admin/volunteers', 'Volunteers', 'nav.volunteers'],
                        ['admin/questions/templates', 'Templates', 'nav.questions'],
                        ['admin/activity-log', 'Log', 'nav.log'],
                    ];
                    foreach ($nav as [$url, $label, $ic]):
                        $isActive = $url === 'admin' ? $path === '/admin' : str_starts_with($path, '/' . $url);
                    ?>
                        <li class="nav-item<?= $isActive ? ' active' : '' ?>">
                            <a class="nav-link" href="<?= site_url($url) ?>"><span class="nav-link-icon"><?= icon($ic) ?></span><span class="nav-link-title"><?= esc($label) ?></span></a>
                        </li>
                    <?php endforeach ?>
                </ul>
            </div>
        </div>
    </header>
    <div class="page-wrapper" id="main">
        <?= $this->renderSection('header') ?>
        <div class="page-body">
            <div class="container-xl">
                <?= view('Modules\Core\Views\partials\flash') ?>
                <?= $this->renderSection('content') ?>
            </div>
        </div>
        <footer class="footer footer-transparent d-print-none">
            <div class="container-xl text-secondary small">Times are shown in IST (Asia/Kolkata). Server time: <?= esc(ist(\Modules\Core\Libraries\Clock::db(), 'H:i')) ?></div>
        </footer>
    </div>
</div>
<?= $this->renderSection('boot') ?>
</body>
</html>
