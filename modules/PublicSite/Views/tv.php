<?php /** Full-screen venue display: frontend/entries/tv.js fills it from the public JSON. @var array $slam */ ?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <?= view('Modules\Core\Views\partials\head', ['indexable' => false]) ?>
    <title><?= esc($slam['name']) ?> · TV</title>
    <?= vite_tags('frontend/entries/tv.js') ?>
</head>
<body class="tv-body">
<div class="tv" data-tv="<?= site_url('api/public/slams/' . $slam['slug'] . '/schedule') ?>" data-logo="<?= base_url('brand/kss-logo-2026.png') ?>" data-name="<?= esc($slam['name'], 'attr') ?>" data-venue="<?= esc($slam['venue_name'], 'attr') ?>">
    <header class="tv-top">
        <img src="<?= base_url('brand/slam-logo.svg') ?>" alt="" class="tv-top-logo">
        <div class="tv-top-text"><strong><?= esc($slam['name']) ?></strong><span><?= esc($slam['venue_name']) ?></span></div>
        <div class="tv-chips" data-tv-chips aria-live="polite"></div>
        <div class="tv-clock" data-tv-clock></div>
    </header>
    <main class="tv-stage" data-tv-stage aria-live="polite"><noscript>This screen needs JavaScript.</noscript></main>
    <footer class="tv-next" data-tv-next></footer>
    <button class="tv-full" type="button" data-tv-full>Full screen</button>
</div>
</body>
</html>
