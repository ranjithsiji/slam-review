<?php /** Stage timer for the presenter: frontend/entries/stage.js fills it from the public JSON. @var array $slam */ ?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <?= view('Modules\Core\Views\partials\head', ['indexable' => false]) ?>
    <title><?= esc($slam['name']) ?> · Stage timer</title>
    <?= vite_tags('frontend/entries/stage.js') ?>
</head>
<body class="stage-body">
<div class="stage" data-stage="<?= site_url('api/public/slams/' . $slam['slug'] . '/schedule') ?>" data-name="<?= esc($slam['name'], 'attr') ?>">
    <div class="stage-top"><img src="<?= base_url('brand/slam-logo.svg') ?>" alt="" class="stage-logo"><div class="stage-who ml-text" data-stage-who></div><div class="stage-clock" data-stage-clock></div></div>
    <div class="stage-label" data-stage-label></div>
    <div class="stage-time" data-stage-time role="timer" aria-live="off"></div>
    <div class="stage-sub ml-text" data-stage-sub></div>
    <button class="stage-full" type="button" data-stage-full>Full screen</button>
</div>
</body>
</html>
