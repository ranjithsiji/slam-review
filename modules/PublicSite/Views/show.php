<?php $this->setVar('title', $slam['name'] . ' · schedule'); $this->setVar('indexable', true); ?>
<?= $this->extend('Modules\Core\Views\layouts\public') ?>
<?= $this->section('meta') ?>
<meta property="og:title" content="<?= esc($slam['name'], 'attr') ?>">
<meta property="og:description" content="<?= esc(local_time($slam['opens_at'], 'l, d F Y') . ' · ' . $slam['venue_name'], 'attr') ?>">
<meta property="og:type" content="event">
<meta property="og:url" content="<?= esc($url, 'attr') ?>">
<?php if ($slam['banner_path']): ?><meta property="og:image" content="<?= base_url($slam['banner_path']) ?>"><?php endif ?>
<meta name="twitter:card" content="summary">
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div data-public-schedule="<?= site_url('api/public/slams/' . $slam['slug'] . '/schedule') ?>">
    <?php if ($slam['banner_path']): ?><img src="<?= base_url($slam['banner_path']) ?>" alt="" class="w-100 rounded mb-3" style="max-height:220px;object-fit:cover"><?php endif ?>
    <header class="slam-head">
        <p class="slam-eyebrow">Kerala Science Slam – <?= esc(local_time($slam['opens_at'], 'j M')) ?> – <?= esc($slam['venue_name']) ?></p>
        <div class="d-flex flex-wrap align-items-start gap-2">
            <h1 class="slam-title me-auto ml-text"><?= esc($slam['name']) ?></h1>
            <?= status_badge('public', $doc['badge']) ?>
        </div>
        <div class="slam-where">
            <span><?= icon('nav.slams', 'icon-inline') ?><?= esc(local_time($slam['opens_at'], 'j F Y')) ?></span>
            <?php if ($slam['checkin_opens_at']): ?><span><?= icon('phase.checkin_open', 'icon-inline') ?>Doors open <?= esc(local_time($slam['checkin_opens_at'], 'H:i')) ?></span><?php endif ?>
            <span><?= icon('place.venue', 'icon-inline') ?><?= esc($slam['venue_name']) ?><?= $slam['city'] && stripos($slam['venue_name'], $slam['city']) === false ? ', ' . esc($slam['city']) : '' ?></span>
            <?php if ($slam['map_url']): ?><a href="<?= esc($slam['map_url']) ?>" target="_blank" rel="noopener noreferrer">Map</a><?php endif ?>
            <?php if ($slam['contact_name']): ?><span class="small"><?= icon('contact.phone', 'icon-inline') ?><?= esc($slam['contact_name']) ?><?= $slam['contact_phone'] ? ' ' . esc($slam['contact_phone']) : '' ?></span><?php endif ?>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <a class="btn btn-sm" href="<?= site_url('slams/' . $slam['slug'] . '/presenters') ?>"><?= icon('nav.presenters') ?>Presenters</a>
            <a class="btn btn-sm" href="<?= site_url('slams/' . $slam['slug'] . '/calendar.ics') ?>"><?= icon('nav.slams') ?>Add to calendar</a>
        </div>
    </header>
    <?php
    $banner = '';
    if ($doc['finished']) {
        $banner = 'Slam finished — thank you!';
    } elseif ($doc['now']) {
        $banner = $doc['now']['kind'] === 'break' ? 'Now: ' . esc($doc['now']['label']) : 'Now: <strong>' . esc($doc['now']['presenter_name']) . '</strong> — ' . esc($doc['now']['topic']);
    } elseif ($doc['next']) {
        $banner = 'Up next: <strong>' . esc($doc['next']['kind'] === 'break' ? $doc['next']['label'] : $doc['next']['presenter_name']) . '</strong> at ' . esc(local_time(str_replace(['T', 'Z'], [' ', ''], (string) $doc['next']['planned_start_at']), 'H:i'));
    }
    if (! empty($doc['paused']) && ! $doc['finished']) {
        $banner = '<span class="badge bg-orange-lt me-2">Paused</span>The programme is paused for a short while and will continue shortly. ' . $banner;
    }
    if ($doc['late_minutes'] > 0 && ! $doc['finished']) {
        $banner .= ' <span class="badge bg-orange-lt ms-2">Running about ' . (int) $doc['late_minutes'] . ' min behind schedule</span>';
    }
    ?>
    <div data-talk-sig="<?= esc(($doc['talk']['title'] ?? '') . '|' . count($doc['ads'] ?? []), 'attr') ?>">
        <?php if (! empty($doc['talk'])): ?><div class="talk-strip my-3"><?= view('Modules\PublicSite\Views\partials\talk', ['talk' => $doc['talk']]) ?></div><?php endif ?>
        <?= view('Modules\PublicSite\Views\partials\ads', ['ads' => $doc['ads'] ?? []]) ?>
    </div>
    <div class="alert alert-info my-3" data-banner role="status" aria-live="polite" <?= $banner === '' ? 'hidden' : '' ?>><?= $banner ?></div>

    <h2 class="visually-hidden">Schedule</h2>
    <?php
    $liveBadge = static fn (string $status): string => match ($status) {
        'presenting' => status_badge('slot', 'open', 'Presenting now'),
        'break_now'  => status_badge('slot', 'open', 'On now'),
        'done'       => status_badge('slot', 'closed', 'Done'),
        default      => '',
    };
    $liveClass = static fn (string $status): string => in_array($status, ['presenting', 'break_now'], true) ? ' is-live' : '';
    // Display-only extras have no slot, so live updates skip them.
    $slotAttrs = static fn (array $r): string => isset($r['id']) ? 'data-slot="' . (int) $r['id'] . '" data-start="' . esc($r['planned_start_at'], 'attr') . '"' : '';
    $times     = static fn (array $r): string => '<span class="slam-tt-time"><span data-time>' . esc(local_time($r['start_at'], 'H:i')) . '</span><small>' . esc(local_time($r['end_at'], 'H:i')) . '</small></span>';
    ?>
    <ol class="slam-tt" data-slots>
        <?php foreach ($programme as $sec): ?>
            <?php if ($sec['kind'] === 'break'): $r = $sec['row']; ?>
                <li class="slam-tt-break<?= $liveClass($r['status']) ?>" <?= $slotAttrs($r) ?>>
                    <?= $times($r) ?>
                    <span class="slam-tt-body ml-text"><?= icon('break.' . ($r['break_type'] ?? 'other'), 'icon-inline me-1') ?><?= esc($r['label']) ?></span>
                    <span class="slam-tt-end"><span data-status><?= $liveBadge($r['status']) ?></span><span class="slam-tt-num"><?= (int) $r['duration_minutes'] ?>′</span></span>
                </li>
                <?php continue; endif ?>
            <?php if (! empty($sec['title'])): ?><li class="slam-tt-session">
                <h3 class="ml-text"><?= esc($sec['title']) ?></h3>
                <span class="slam-tt-range"><?= esc(local_time($sec['start_at'], 'H:i')) ?> – <?= esc(local_time($sec['end_at'], 'H:i')) ?></span>
                <?php if (! empty($sec['note'])): ?><p class="ml-text"><?= esc($sec['note']) ?></p><?php endif ?>
            </li><?php endif ?>
            <?php foreach ($sec['rows'] as $r): ?>
                <?php if ($r['kind'] !== 'performance'): ?>
                    <li class="slam-tt-item<?= $liveClass($r['status']) ?>" <?= $slotAttrs($r) ?>>
                        <?= $times($r) ?>
                        <span class="slam-tt-body ml-text"><?= esc($r['label']) ?></span>
                        <span class="slam-tt-end"><span data-status><?= $liveBadge($r['status']) ?></span></span>
                    </li>
                <?php else: ?>
                    <li class="slam-tt-talk<?= $liveClass($r['status']) ?>" <?= $slotAttrs($r) ?>>
                        <?= $times($r) ?>
                        <a class="slam-tt-body" href="<?= site_url('slams/' . $slam['slug'] . '/presenters') ?>#p-<?= (int) $r['id'] ?>">
                            <span class="slam-tt-avatar"><?php if ($r['thumb']): ?><img src="<?= esc($r['thumb'], 'attr') ?>" alt="" loading="lazy"><?php else: ?><?= icon('nav.presenters') ?><?php endif ?></span>
                            <span class="min-w-0">
                                <span class="slam-tt-topic ml-text"><?= esc($r['topic']) ?></span>
                                <span class="slam-tt-who ml-text"><?= esc($r['presenter_name']) ?><?= $r['designation'] ? ' · ' . esc($r['designation']) : '' ?></span>
                            </span>
                        </a>
                        <span class="slam-tt-end"><span data-status><?= $liveBadge($r['status']) ?></span><span class="slam-tt-num">#<?= (int) $r['number'] ?></span></span>
                    </li>
                <?php endif ?>
            <?php endforeach ?>
        <?php endforeach ?>
    </ol>
</div>
<?= $this->endSection() ?>
