<?php $this->setVar('title', $slam['name'] . ' · schedule'); $this->setVar('indexable', true); ?>
<?php $this->setVar('ownToggle', true); ?>
<?= $this->extend('Modules\Core\Views\layouts\public') ?>
<?= $this->section('meta') ?>
<meta property="og:title" content="<?= esc($slam['name'], 'attr') ?>">
<meta property="og:description" content="<?= esc(local_time($slam['opens_at'], 'l, d F Y') . ' · ' . $slam['venue_name'], 'attr') ?>">
<meta property="og:type" content="event">
<meta property="og:url" content="<?= esc($url, 'attr') ?>">
<?php if ($slam['banner_path']): ?><meta property="og:image" content="<?= base_url($slam['banner_path']) ?>"><?php endif ?>
<meta name="twitter:card" content="summary">
<?= $this->endSection() ?>
<?= $this->section('top') ?>
<header class="navbar navbar-expand"><div class="container-xl">
    <a href="<?= site_url('slams') ?>" class="navbar-brand"><?= brand_logo('brand-logo-nav') ?></a>
    <div class="ms-auto"><?= theme_toggle() ?></div>
</div></header>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div data-public-schedule="<?= site_url('api/public/slams/' . $slam['slug'] . '/schedule') ?>">
    <?php if ($slam['banner_path']): ?><img src="<?= base_url($slam['banner_path']) ?>" alt="" class="w-100 rounded mb-3" style="max-height:220px;object-fit:cover"><?php endif ?>
    <div class="d-flex flex-wrap align-items-start gap-2 mb-2">
        <div class="me-auto">
            <h1 class="mb-1"><?= esc($slam['name']) ?></h1>
            <div class="text-secondary"><?= icon('nav.slams', 'icon-inline me-1') ?><?= esc(local_time($slam['opens_at'], 'l, d F Y')) ?>
                <?php if ($slam['checkin_opens_at']): ?> · doors open <?= esc(local_time($slam['checkin_opens_at'], 'H:i')) ?><?php endif ?></div>
            <div class="text-secondary"><?= icon('place.venue', 'icon-inline me-1') ?><?= esc($slam['venue_name']) ?>, <?= esc(implode(', ', array_filter([$slam['address_line'], $slam['city']]))) ?>
                <?php if ($slam['map_url']): ?> · <a href="<?= esc($slam['map_url']) ?>" target="_blank" rel="noopener noreferrer">Map</a><?php endif ?></div>
            <?php if ($slam['contact_name']): ?><div class="text-secondary small"><?= icon('contact.phone', 'icon-inline me-1') ?><?= esc($slam['contact_name']) ?> <?= esc((string) $slam['contact_phone']) ?></div><?php endif ?>
        </div>
        <div class="text-end">
            <?= status_badge('public', $doc['badge']) ?>
            <div><a class="btn btn-sm mt-2" href="<?= site_url('slams/' . $slam['slug'] . '/calendar.ics') ?>"><?= icon('nav.slams') ?>Add to calendar</a></div>
        </div>
    </div>
    <?php
    $banner = '';
    if ($doc['finished']) {
        $banner = 'Slam finished — thank you!';
    } elseif ($doc['now']) {
        $banner = $doc['now']['kind'] === 'break' ? 'Now: ' . esc($doc['now']['label']) : 'Now: <strong>' . esc($doc['now']['presenter_name']) . '</strong> — ' . esc($doc['now']['topic']);
    } elseif ($doc['next']) {
        $banner = 'Up next: <strong>' . esc($doc['next']['kind'] === 'break' ? $doc['next']['label'] : $doc['next']['presenter_name']) . '</strong> at ' . esc(local_time(str_replace(['T', 'Z'], [' ', ''], (string) $doc['next']['planned_start_at']), 'H:i'));
    }
    if ($doc['late_minutes'] > 0 && ! $doc['finished']) {
        $banner .= ' <span class="badge bg-orange-lt ms-2">Running about ' . (int) $doc['late_minutes'] . ' min behind schedule</span>';
    }
    ?>
    <div class="alert alert-info my-3" data-banner role="status" aria-live="polite" <?= $banner === '' ? 'hidden' : '' ?>><?= $banner ?></div>

    <h2 class="h3 mt-4">Running order</h2>
    <div class="row row-cards" data-slots>
        <?php foreach ($doc['slots'] as $s): $isBreak = $s['kind'] === 'break'; ?>
            <div class="col-12" data-slot="<?= (int) $s['id'] ?>">
                <div class="card slot-card <?= $isBreak ? 'slot-break' : '' ?>">
                    <div class="card-body">
                        <div class="d-flex gap-3 align-items-start">
                            <div class="text-center" style="min-width:3.5rem"><div class="h3 m-0" data-time><?= esc(local_time(str_replace(['T', 'Z'], [' ', ''], (string) $s['planned_start_at']), 'H:i')) ?></div><div class="small text-secondary"><?= (int) $s['duration_minutes'] ?> min</div></div>
                            <?php if ($isBreak): ?>
                                <?= icon('break.' . ($s['break_type'] ?? 'other'), 'icon-lg text-orange mt-1') ?>
                                <div class="flex-fill"><div data-status class="mb-1"><?= status_badge('slot', match ($s['status']) { 'presenting', 'break_now' => 'open', 'done' => 'closed', default => 'pending' }, match ($s['status']) { 'presenting' => 'Presenting now', 'break_now' => 'On now', 'done' => 'Done', default => 'Upcoming' }) ?></div><div class="fw-bold ml-text"><?= esc($s['label']) ?></div></div>
                            <?php else: ?>
                                <?php if ($s['thumb']): ?><img class="avatar avatar-md avatar-photo" src="<?= esc($s['thumb']) ?>" alt="" loading="lazy" width="48" height="48"><?php else: ?><span class="avatar avatar-md"><?= icon('person.user') ?></span><?php endif ?>
                                <div class="flex-fill min-w-0">
                                    <div data-status class="mb-1"><?= status_badge('slot', match ($s['status']) { 'presenting', 'break_now' => 'open', 'done' => 'closed', default => 'pending' }, match ($s['status']) { 'presenting' => 'Presenting now', 'break_now' => 'On now', 'done' => 'Done', default => 'Upcoming' }) ?></div>
                                    <div class="fw-bold ml-text"><?= esc($s['presenter_name']) ?></div>
                                    <?php if ($s['designation']): ?><div class="small text-secondary ml-text"><?= esc($s['designation']) ?></div><?php endif ?>
                                    <div class="ml-text"><?= esc($s['topic']) ?></div>
                                    <?php if ($s['abstract'] || $s['profile']): ?>
                                        <details class="mt-1"><summary class="text-primary small">About this talk</summary>
                                            <?php if ($s['abstract']): ?><p class="mt-2 mb-1 ml-text"><?= nl2br_esc($s['abstract']) ?></p><?php endif ?>
                                            <?php if ($s['profile']): ?><p class="small text-secondary mb-0 ml-text"><?= nl2br_esc($s['profile']) ?></p><?php endif ?>
                                        </details>
                                    <?php endif ?>
                                </div>
                            <?php endif ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach ?>
    </div>
</div>
<?= $this->endSection() ?>
