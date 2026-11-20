<?php /** @var array{slams: list<array>, places: list<array>, unplaced: list<array>, total: int} $map */ ?>
<?php $this->setVar('title', 'Presenters map'); $this->setVar('indexable', true); $this->setVar('entry', 'frontend/entries/map.js'); ?>
<?= $this->extend('Modules\Core\Views\layouts\public') ?>
<?= $this->section('content') ?>
<?php
$colours = ['ernakulam' => '#1565c0', 'kollam' => '#2e7d32', 'thrissur' => '#e65100', 'kannur' => '#ad1457', 'final' => '#b8860b'];
$colour = static fn (string $slug): string => $colours[$slug] ?? '#555555';
$slams = array_map(static fn (array $s): array => $s + ['colour' => $colour($s['slug'])], $map['slams']);
?>
<header class="slam-head">
    <p class="slam-eyebrow">Kerala Science Slam 2026</p>
    <h1 class="slam-title">Where our presenters come from</h1>
    <p class="text-secondary mb-0"><?= (int) $map['total'] ?> presenters from <?= count($map['places']) ?> institutions. One dot for each presenter, coloured by their slam; tap a dot for the talk.</p>
</header>

<div class="pmap-legend my-3" role="group" aria-label="Show slams">
    <?php foreach ($slams as $s): ?>
        <label class="pmap-chip"><input type="checkbox" checked data-pmap-slam="<?= esc($s['slug'], 'attr') ?>">
            <span class="pmap-dot" style="background:<?= esc($s['colour'], 'attr') ?>"></span><?= esc($s['name']) ?> <span class="text-secondary">· <?= esc($s['date']) ?></span></label>
    <?php endforeach ?>
    <button type="button" class="btn btn-sm ms-auto" data-pmap-all><?= icon('place.venue') ?>Show everyone (India and abroad)</button>
    <button type="button" class="btn btn-sm" data-pmap-kerala hidden>Back to Kerala</button>
</div>

<div class="pmap" data-pmap-boundaries="<?= base_url('maps/kerala-tamilnadu.geojson') ?>" data-pmap='<?= esc(json_encode(['slams' => $slams, 'places' => $map['places']], JSON_UNESCAPED_UNICODE), 'attr') ?>' aria-label="Map of presenter institutions"></div>
<p class="small text-secondary mt-1">Map © <a href="https://www.openstreetmap.org/copyright" rel="noopener">OpenStreetMap</a> contributors. State and district boundaries: <a href="https://github.com/datameet/maps" rel="noopener">DataMeet</a> (CC BY 2.5 India), via Wikimedia Commons. Places marked "town" are shown at the town, not the exact campus.</p>

<section class="my-4">
    <h2 class="h3">Institutions</h2>
    <div class="row g-3">
        <?php foreach ($map['places'] as $p): ?>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card h-100"><div class="card-body">
                    <div class="fw-bold"><?= esc($p['name']) ?> <span class="badge bg-secondary-lt"><?= count($p['people']) ?></span><?= $p['approx'] ? ' <span class="badge bg-secondary-lt">town</span>' : '' ?></div>
                    <ul class="list-unstyled mb-0 mt-2">
                        <?php foreach ($p['people'] as $person): ?>
                            <li class="d-flex align-items-start gap-2 mb-1"><span class="pmap-dot mt-1" style="background:<?= esc($colour($person['slam']), 'attr') ?>"></span>
                                <span><span class="ml-text"><?= esc($person['name']) ?></span><br><span class="small text-secondary ml-text"><?= esc($person['topic']) ?></span></span></li>
                        <?php endforeach ?>
                    </ul>
                </div></div>
            </div>
        <?php endforeach ?>
    </div>
    <?php if ($map['unplaced'] !== []): ?>
        <h3 class="h4 mt-4">Not on the map yet</h3>
        <ul><?php foreach ($map['unplaced'] as $person): ?><li><span class="ml-text"><?= esc($person['name']) ?></span> · <?= esc($person['institution'] ?? '') ?></li><?php endforeach ?></ul>
    <?php endif ?>
</section>
<?= $this->endSection() ?>
