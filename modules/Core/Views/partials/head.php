<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= csrf_hash() ?>">
<meta name="csrf-header" content="<?= csrf_header() ?>">
<meta name="icon-sprite" content="<?= base_url('assets/icons.svg') ?>">
<meta name="color-scheme" content="light dark">
<?php if (! ($indexable ?? false)): ?><meta name="robots" content="noindex, nofollow"><?php endif ?>
<link rel="icon" href="<?= base_url('favicon.ico') ?>">
<script src="<?= base_url('theme-init.js') ?>"></script>
