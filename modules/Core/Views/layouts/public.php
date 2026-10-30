<!doctype html>
<html lang="en">
<head>
    <?= view('Modules\Core\Views\partials\head', ['indexable' => $indexable ?? false]) ?>
    <title><?= esc($title ?? 'Science Slam') ?></title>
    <?= $this->renderSection('meta') ?>
    <?= vite_tags($entry ?? 'frontend/entries/public.js') ?>
</head>
<body class="site-body d-flex flex-column">
<a class="visually-hidden-focusable" href="#main">Skip to content</a>
<?= view('Modules\Core\Views\partials\site_header') ?>
<?= $this->renderSection('hero') ?>
<main id="main" class="site-main flex-fill<?= ($narrow ?? false) ? ' d-flex flex-column justify-content-center' : '' ?>">
    <div class="<?= ($narrow ?? false) ? 'container container-tight py-5' : 'container-xl py-4' ?>">
        <?= view('Modules\Core\Views\partials\flash') ?>
        <?= $this->renderSection('content') ?>
    </div>
</main>
<?= $this->renderSection('beforefooter') ?>
<?= view('Modules\Core\Views\partials\site_footer') ?>
<?= $this->renderSection('boot') ?>
</body>
</html>
