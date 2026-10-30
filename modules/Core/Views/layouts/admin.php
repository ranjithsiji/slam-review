<?php $auth = session('auth'); ?>
<!doctype html>
<html lang="en" data-bs-navbar-position="combined">
<head>
    <?= view('Modules\Core\Views\partials\head') ?>
    <title><?= esc($title ?? 'Admin') ?> · Science Slam</title>
    <?= vite_tags($entry ?? 'frontend/entries/admin.js') ?>
</head>
<body class="layout-fluid">
<a class="visually-hidden-focusable" href="#main">Skip to content</a>
<?php
$path = '/' . trim(service('request')->getUri()->getPath(), '/');
// The slam being looked at (if any) is carried into the slam-less sidebar links.
$ctxSlam = preg_match('#^/admin/slams/(\d+)#', $path, $m) ? (int) $m[1] : null;
$q       = $ctxSlam ? '?slam=' . $ctxSlam : '';
// [url, label, icon, active-path regex]; a plain string is a section heading.
$nav  = [
    ['admin', 'Dashboard', 'nav.dashboard', '#^/admin$#'],
    ['admin/slams', 'Slams', 'nav.slams', '#^/admin/slams(?!/\d+/(reviewers|judges|judge-marks|representatives))#'],
    ['admin/presenters', 'Presenters', 'nav.presenters', '#^/admin/presenters#'],
    'Participants',
    ['admin/reviewers' . $q, 'Reviewers', 'person.user', '#^/admin/slams/\d+/reviewers#'],
    ['admin/judges' . $q, 'Judges', 'person.judge', '#^/admin/slams/\d+/(judges|judge-marks)#'],
    ['admin/representatives' . $q, 'Audience representatives', 'nav.representatives', '#^/admin/slams/\d+/representatives#'],
    ['admin/volunteers', 'Volunteers', 'nav.volunteers', '#^/admin/volunteers#'],
    'Setup',
    ['admin/qr', 'Badges & QR', 'nav.qr', '#^/admin/qr#'],
    ['admin/questions/templates', 'Question templates', 'nav.questions', '#^/admin/questions#'],
    ['admin/activity-log', 'Activity log', 'nav.log', '#^/admin/activity-log#'],
    ['admin/settings', 'Settings', 'nav.system', '#^/admin/settings#'],
];
$userMenu = static function (array $auth, string $class): string {
    ob_start(); ?>
    <div class="nav-item dropdown <?= $class ?>">
        <a href="#" class="nav-link d-flex lh-1 text-reset p-0" data-bs-toggle="dropdown" aria-label="Account menu">
            <span class="avatar avatar-sm"><?= esc(mb_strtoupper(mb_substr($auth['name'] ?? '?', 0, 1))) ?></span>
            <div class="d-none d-xl-block ps-2">
                <div><?= esc($auth['name'] ?? '') ?></div>
                <div class="mt-1 small text-secondary"><?= esc($auth['type'] ?? '') ?></div>
            </div>
        </a>
        <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
            <a class="dropdown-item" href="<?= site_url('/') ?>"><?= icon('nav.home', 'me-2') ?>Public home page</a>
            <form method="post" action="<?= site_url('logout') ?>"><?= csrf_field() ?>
                <button class="dropdown-item" type="submit"><?= icon('action.logout', 'me-2') ?>Sign out</button>
            </form>
        </div>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<div class="page">
    <!-- Tabler "fluid vertical" layout: dark sidebar, full-width content. -->
    <aside class="navbar navbar-vertical navbar-expand-lg d-print-none" data-bs-theme="dark">
        <div class="container-fluid">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-controls="sidebar-menu" aria-expanded="false" aria-label="Menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <a class="navbar-brand navbar-brand-autodark" href="<?= site_url('admin') ?>" aria-label="Science Slam admin home">
                <?= brand_logo('brand-logo-sidebar') ?>
            </a>
            <div class="navbar-nav flex-row d-lg-none align-items-center gap-2">
                <?= theme_toggle('nav-link px-2') ?>
                <?= $userMenu($auth ?? [], '') ?>
            </div>
            <div class="collapse navbar-collapse" id="sidebar-menu">
                <ul class="navbar-nav pt-lg-3">
                    <?php foreach ($nav as $entry):
                        if (is_string($entry)): ?>
                            <li class="nav-item"><div class="nav-section-title"><?= esc($entry) ?></div></li>
                            <?php continue;
                        endif;
                        [$url, $label, $ic, $match] = $entry;
                        $isActive = (bool) preg_match($match, $path); ?>
                        <li class="nav-item<?= $isActive ? ' active' : '' ?>">
                            <a class="nav-link" href="<?= site_url($url) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>><span class="nav-link-icon d-md-none d-lg-inline-block"><?= icon($ic) ?></span><span class="nav-link-title"><?= esc($label) ?></span></a>
                        </li>
                    <?php endforeach ?>
                </ul>
                <!-- Signed-in user and Sign out, always visible at the bottom of the sidebar. -->
                <div class="navbar-side px-3 pb-3 pt-4">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="avatar avatar-sm"><?= esc(mb_strtoupper(mb_substr($auth['name'] ?? '?', 0, 1))) ?></span>
                        <div class="lh-sm min-w-0"><div class="text-truncate"><?= esc($auth['name'] ?? '') ?></div><div class="small text-secondary"><?= esc($auth['type'] ?? '') ?></div></div>
                    </div>
                    <form method="post" action="<?= site_url('logout') ?>"><?= csrf_field() ?>
                        <button class="btn btn-outline-secondary w-100" type="submit"><?= icon('action.logout') ?>Sign out</button>
                    </form>
                </div>
            </div>
        </div>
    </aside>
    <header class="navbar navbar-expand-md d-none d-lg-flex d-print-none">
        <div class="container-fluid">
            <div class="text-secondary small me-auto"><?= icon('status.time', 'icon-inline me-1') ?><?= esc(local_time(\Modules\Core\Libraries\Clock::db(), 'D d M, H:i')) ?> <?= esc(tz_label()) ?></div>
            <div class="navbar-nav flex-row align-items-center gap-2">
                <?= theme_toggle('nav-link px-2') ?>
                <?= $userMenu($auth ?? [], '') ?>
                <form method="post" action="<?= site_url('logout') ?>" class="ms-1"><?= csrf_field() ?>
                    <button class="btn btn-sm" type="submit"><?= icon('action.logout') ?>Sign out</button>
                </form>
            </div>
        </div>
    </header>
    <div class="page-wrapper" id="main">
        <div id="live-bar" class="live-bar d-print-none" role="status" aria-live="off" aria-label="Slams running now" hidden></div>
        <?php
        $headerHtml = $this->renderSection('header');
        $isDashboard = $path === '/admin';
        ?>
        <?php if (trim($headerHtml) !== ''): ?>
            <?= $headerHtml ?>
        <?php elseif (! $isDashboard): ?>
            <div class="page-header d-print-none"><div class="container-fluid">
                <?= view('Modules\Core\Views\partials\breadcrumb', ['crumbs' => $breadcrumbs ?? [[$title ?? 'Page', null]]]) ?>
                <?php if (! empty($pageActions)): ?><div class="d-flex align-items-center gap-2"><h2 class="page-title me-auto"><?= esc($title ?? '') ?></h2><?= $pageActions ?></div><?php endif ?>
            </div></div>
        <?php endif ?>
        <div class="page-body">
            <div class="container-fluid">
                <?= view('Modules\Core\Views\partials\flash') ?>
                <?= $this->renderSection('content') ?>
            </div>
        </div>
        <footer class="footer footer-transparent d-print-none">
            <div class="container-fluid text-secondary small">Times are shown in <?= esc(tz_label()) ?> (<?= esc(\Modules\Core\Libraries\Clock::tz()) ?>). Server time: <?= esc(local_time(\Modules\Core\Libraries\Clock::db(), "H:i")) ?> · <a href="<?= site_url("admin/settings") ?>" class="text-secondary">Change</a></div>
        </footer>
    </div>
</div>
<?= $this->renderSection('boot') ?>
</body>
</html>
