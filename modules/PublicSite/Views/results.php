<?php
/** @var array $slam  @var list<array> $top  @var string $url */
$this->setVar('title', $slam['name'] . ' · results');
$this->setVar('indexable', true);
?>
<?= $this->extend('Modules\Core\Views\layouts\public') ?>
<?= $this->section('meta') ?>
<meta property="og:title" content="<?= esc($slam['name'] . ' · results', 'attr') ?>">
<meta property="og:description" content="<?= esc(local_time($slam['opens_at'], 'l, d F Y') . ' · ' . $slam['venue_name'], 'attr') ?>">
<meta property="og:type" content="article">
<meta property="og:url" content="<?= esc($url, 'attr') ?>">
<meta name="twitter:card" content="summary">
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<header class="slam-head">
    <p class="slam-eyebrow">Kerala Science Slam – <?= esc(local_time($slam['opens_at'], 'j M')) ?> – <?= esc($slam['venue_name']) ?></p>
    <h1 class="slam-title ml-text"><?= esc($slam['name']) ?>: results</h1>
    <div class="slam-where">
        <span><?= icon('nav.slams', 'icon-inline') ?><?= esc(local_time($slam['opens_at'], 'j F Y')) ?></span>
        <span><?= icon('place.venue', 'icon-inline') ?><?= esc($slam['venue_name']) ?><?= $slam['city'] && stripos($slam['venue_name'], $slam['city']) === false ? ', ' . esc($slam['city']) : '' ?></span>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-3">
        <a class="btn btn-sm" href="<?= site_url('slams/' . $slam['slug'] . '/presenters') ?>"><?= icon('nav.presenters') ?>All presenters</a>
    </div>
</header>
<?= view('Modules\PublicSite\Views\partials\result', ['rs' => $slam, 'top' => $top, 'link' => false]) ?>
<?php if ($slam['type'] === 'regional'): ?>
    <p class="text-secondary">These presenters are selected for the final. Names are listed in alphabetical order.</p>
<?php endif ?>
<?= $this->endSection() ?>
