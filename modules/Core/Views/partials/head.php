<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= csrf_hash() ?>">
<meta name="csrf-header" content="<?= csrf_header() ?>">
<meta name="brand-logo" content="<?= base_url('brand/slam-logo-on-light.svg') ?>">
<meta name="brand-logo-dark" content="<?= base_url('brand/slam-logo.svg') ?>">
<meta name="icon-sprite" content="<?= base_url('assets/icons.svg') ?>">
<meta name="color-scheme" content="light dark">
<?php if (! ($indexable ?? false)): ?><meta name="robots" content="noindex, nofollow"><?php endif ?>
<link rel="icon" href="<?= base_url('favicon.ico') ?>">
<script src="<?= base_url('theme-init.js') ?>"></script>
<meta name="app-tz" content="<?= esc(\Modules\Core\Libraries\Clock::tz(), 'attr') ?>">
<meta name="app-tz-label" content="<?= esc(\Modules\Core\Libraries\Clock::tzLabel(), 'attr') ?>">
<meta name="app-time-format" content="<?= \Modules\Core\Libraries\Clock::is12h() ? '12h' : '24h' ?>">
