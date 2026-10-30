<!doctype html>
<html lang="en">
<head>
    <?= view('Modules\Core\Views\partials\head', ['indexable' => $indexable ?? false]) ?>
    <title><?= esc($title ?? 'Science Slam') ?></title>
    <?= $this->renderSection('meta') ?>
    <?= vite_tags($entry ?? 'frontend/entries/public.js') ?>
</head>
<body class="d-flex flex-column">
<div class="page<?= ($narrow ?? false) ? ' page-center' : '' ?> position-relative">
    <?php if (empty($ownToggle)): ?><?= theme_toggle('theme-toggle-float') ?><?php endif ?>
    <?= $this->renderSection('top') ?>
    <div class="<?= ($narrow ?? false) ? 'container container-tight py-4' : 'container-xl py-3' ?>" id="main">
        <?= view('Modules\Core\Views\partials\flash') ?>
        <?= $this->renderSection('content') ?>
    </div>
</div>
<?= $this->renderSection('boot') ?>
</body>
</html>
