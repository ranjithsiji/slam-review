<?php
/** Public site header + menu (modelled on scienceslam.in). Used by every public page and the login screens. */
$site = config(\Modules\Core\Config\Site::class);
$path = '/' . trim(service('request')->getUri()->getPath(), '/');
$menu = [
    ['Home', '/', '#^/$#'],
    ['Schedule', '/slams', '#^/slams#'],
    ['Check in', '/checkin', '#^/(checkin|in/)#'],
    ['Am I registered?', '/registered', '#^/registered#'],
    ['About', '/about', '#^/about#'],
];
?>
<header class="site-header navbar navbar-expand-md d-print-none">
    <div class="container-xl">
        <a class="navbar-brand site-brand d-flex align-items-center gap-3" href="<?= site_url('/') ?>">
            <img src="<?= base_url('brand/slamicon.png') ?>" alt="" width="48" height="48" class="site-brand-icon">
            <span class="site-brand-name"><?= esc($site->name) ?></span>
        </a>
        <button class="navbar-toggler" type="button" data-site-menu-toggle aria-controls="site-menu" aria-expanded="false" aria-label="Menu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="site-menu">
            <ul class="navbar-nav ms-md-auto align-items-md-center gap-md-1">
                <?php foreach ($menu as [$label, $url, $match]): $on = (bool) preg_match($match, $path); ?>
                    <li class="nav-item"><a class="nav-link<?= $on ? ' active' : '' ?>" href="<?= site_url($url) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= esc($label) ?></a></li>
                <?php endforeach ?>
                <li class="nav-item ms-md-2 py-2 py-md-0"><a class="btn btn-slam" href="<?= site_url('checkin') ?>">Check in</a></li>
                <li class="nav-item ms-md-1"><?= theme_toggle('') ?></li>
            </ul>
        </div>
    </div>
</header>
