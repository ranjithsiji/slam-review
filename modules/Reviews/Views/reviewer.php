<!doctype html>
<html lang="en">
<head>
    <?= view('Modules\Core\Views\partials\head') ?>
    <meta name="theme-color" content="#1a2234">
    <title>Review · Science Slam</title>
    <?= vite_tags('frontend/entries/reviewer.js') ?>
</head>
<body>
<div id="reviewer-app"><div class="text-center text-secondary py-5"><div class="spinner-border" role="status"></div><div>Loading…</div></div></div>
<noscript><div class="container py-4"><div class="alert alert-warning">Please enable JavaScript to review performances.</div></div></noscript>
<?= boot_json($boot) ?>
</body>
</html>
