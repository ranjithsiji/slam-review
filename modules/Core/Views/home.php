<?php
/** @var list<array> $coming  @var list<array> $past  @var list<array> $live */
$this->setVar('title', 'Kerala Science Slam');
$sig = static fn (array $l) => implode('|', array_map(static fn ($d) => $d['slug'] . ':' . ($d['now']['id'] ?? '') . ':' . ($d['next']['id'] ?? '') . ':' . $d['phase'] . ':' . (int) $d['paused'] . ':' . count($d['ads'] ?? []) . ':' . ($d['talk']['title'] ?? ''), $l));
$slotTime = static fn (string $iso) => esc(local_time(str_replace(['T', 'Z'], [' ', ''], $iso), 'H:i'));
?>
<?= $this->extend('Modules\Core\Views\layouts\public') ?>

<?= $this->section('hero') ?>
<section class="site-hero">
    <div class="site-hero-shade">
        <div class="container-xl text-center site-hero-inner">
            <h1 class="m-0"><img src="<?= base_url('brand/kss-logo-2026.png') ?>" alt="Kerala Science Slam 2026" class="site-hero-logo" width="294" height="200"></h1>
            <p class="site-hero-lead mx-auto mt-3 mb-0">Researchers explain their project to everyone, in ten minutes, without slides. You review each talk live.</p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<!-- The three entry cards: kept exactly as the organisers like them. -->
<div class="row g-3 justify-content-center mb-2">
    <div class="col-12 col-md-4">
        <a class="card card-link card-link-pop h-100" href="<?= site_url('checkin') ?>">
            <div class="card-body text-center py-4">
                <?= icon('qr.scan', 'icon-xl text-primary mb-2') ?>
                <h3 class="card-title mb-1">Check in to review</h3>
                <div class="text-secondary">Scan your badge or type your ID</div>
            </div>
        </a>
    </div>
    <div class="col-12 col-md-4">
        <a class="card card-link card-link-pop h-100" href="<?= site_url('slams') ?>">
            <div class="card-body text-center py-4">
                <?= icon('nav.schedule', 'icon-xl text-green mb-2') ?>
                <h3 class="card-title mb-1">Schedules</h3>
                <div class="text-secondary"><?= count($coming) + count($past) ?> published slam<?= count($coming) + count($past) === 1 ? '' : 's' ?></div>
            </div>
        </a>
    </div>
    <div class="col-12 col-md-4">
        <a class="card card-link card-link-pop h-100" href="<?= site_url('registered') ?>">
            <div class="card-body text-center py-4">
                <?= icon('person.badge', 'icon-xl text-orange mb-2') ?>
                <h3 class="card-title mb-1">Am I registered?</h3>
                <div class="text-secondary">Check with your mobile number</div>
            </div>
        </a>
    </div>
</div>
<div class="text-center mb-4 small">
    <a href="<?= site_url('volunteer/login') ?>" class="text-secondary me-3">Volunteer sign-in</a>
    <a href="<?= site_url('admin/login') ?>" class="text-secondary">Organiser sign-in</a>
</div>

<!-- Happening now: shown while a published slam is running; refreshed from /api/public/live. -->
<div data-home-live="<?= site_url('api/public/live') ?>" data-sig="<?= esc($sig($live), 'attr') ?>">
    <?php foreach ($live as $d): ?>
        <section class="card card-accent accent-red home-now mb-4" aria-labelledby="now-<?= esc($d['slug'], 'attr') ?>">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="badge bg-red text-red-fg"><?= icon('status.live', 'icon-inline me-1') ?>Happening now</span>
                    <h2 class="h3 m-0" id="now-<?= esc($d['slug'], 'attr') ?>"><?= esc($d['name']) ?></h2>
                    <span class="small text-secondary ms-md-2"><?= icon('place.venue', 'icon-inline me-1') ?><?= esc($d['venue_name']) ?></span>
                </div>
                <p class="h3 fw-normal mb-2 ml-text" data-live-text="<?= esc($d['slug'], 'attr') ?>" role="status" aria-live="polite"><?= live_headline($d) ?></p>
                <?php if (! empty($d['talk'])): ?><div class="talk-strip mb-3"><?= view('Modules\PublicSite\Views\partials\talk', ['talk' => $d['talk']]) ?></div><?php endif ?>
                <?= view('Modules\PublicSite\Views\partials\ads', ['ads' => $d['ads'] ?? []]) ?>
                <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                    <span data-live-late="<?= esc($d['slug'], 'attr') ?>"><?= (int) $d['late_minutes'] > 0 ? '<span class="badge bg-orange-lt">Running about ' . (int) $d['late_minutes'] . ' min behind schedule</span>' : '' ?></span>
                    <a class="btn btn-sm ms-auto" href="<?= site_url('slams/' . $d['slug']) ?>">Full schedule</a>
                </div>
            </div>
        </section>
    <?php endforeach ?>
</div>

<?php foreach ($results as $res): $rs = $res['slam']; ?>
    <section class="card card-accent accent-orange mb-4" aria-labelledby="res-<?= esc($rs['slug'], 'attr') ?>">
        <div class="card-header d-block">
            <div class="text-secondary small text-uppercase fw-bold"><?= icon('nav.marks', 'icon-inline me-1') ?>Result</div>
            <h2 class="h2 m-0" id="res-<?= esc($rs['slug'], 'attr') ?>"><?= $rs['type'] === 'final' ? esc($rs['name']) . ': final result' : esc($rs['name']) . ': top ' . count($res['top']) . ' presenters' ?></h2>
            <div class="text-secondary small"><?= esc(local_time($rs['opens_at'], 'l, d F Y')) ?> · <?= esc($rs['venue_name']) ?><?= $rs['type'] === 'regional' ? ' · selected for the final' : '' ?></div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ($res['top'] as $t): ?>
                    <div class="col-12 col-md-6 col-xl-4">
                        <div class="d-flex gap-3 align-items-center">
                            <?php if ($t['thumb']): ?><img class="avatar avatar-lg avatar-photo" src="<?= esc($t['thumb'], 'attr') ?>" alt="" loading="lazy" width="56" height="56"><?php else: ?><span class="avatar avatar-lg"><?= icon('person.user') ?></span><?php endif ?>
                            <?php if ($rs['type'] === 'final'): ?><span class="badge <?= $t['rank'] === 1 ? 'bg-yellow' : 'bg-orange-lt' ?> fs-3 flex-none" title="Position"><?= $t['rank'] === 1 ? 'Winner' : ($t['rank'] === 2 ? '2nd' : ($t['rank'] === 3 ? '3rd' : $t['rank'] . 'th')) ?></span><?php endif ?>
                            <div class="min-w-0">
                                <div class="fw-bold fs-3 ml-text"><?= esc($t['presenter_name']) ?></div>
                                <?php if ($t['designation']): ?><div class="small text-secondary ml-text"><?= esc($t['designation']) ?></div><?php endif ?>
                                <div class="small ml-text"><?= esc($t['topic']) ?></div>
                                <?php if ($rs['type'] === 'final'): ?><div class="fw-bold"><?= number_format($t['final_mark'] * 10, 2) ?> <span class="small text-secondary fw-normal">/ 100</span></div><?php endif ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach ?>
            </div>
        </div>
    </section>
<?php endforeach ?>

<section aria-labelledby="schedule-h" class="mb-4">
    <h2 class="h2 mb-3" id="schedule-h">Schedule</h2>
    <?php if ($coming === []): ?>
        <div class="empty"><p class="empty-title">The schedule will appear here once it is published.</p><p class="empty-subtitle text-secondary">Check back closer to the event.</p></div>
    <?php endif ?>
    <div class="row row-cards">
        <?php foreach ($coming as $s): $d = $s['doc']; ?>
            <div class="col-12 col-lg-<?= count($coming) > 1 ? '6' : '12' ?>">
                <article class="card card-accent accent-blue h-100">
                    <div class="card-header d-block">
                        <div class="d-flex flex-wrap align-items-start gap-2">
                            <div class="me-auto">
                                <h3 class="card-title h3 mb-1"><?= esc($s['name']) ?></h3>
                                <div class="text-secondary"><?= icon('nav.slams', 'icon-inline me-1') ?><?= esc(local_time($s['opens_at'], 'l, d F Y')) ?><?= $s['checkin_opens_at'] ? ' · doors open ' . esc(local_time($s['checkin_opens_at'], 'H:i')) : '' ?></div>
                                <div class="text-secondary"><?= icon('place.venue', 'icon-inline me-1') ?><?= esc($s['venue_name']) ?><?= $s['city'] ? ', ' . esc($s['city']) : '' ?></div>
                            </div>
                            <?= status_badge('public', $s['badge']) ?>
                        </div>
                    </div>
                    <?php if ($d['slots'] === []): ?>
                        <div class="card-body text-secondary">The running order will be announced soon.</div>
                    <?php else: ?>
                        <ol class="list-group list-group-flush home-order">
                            <?php foreach ($d['slots'] as $slot): $isBreak = $slot['kind'] === 'break'; $now = in_array($slot['status'], ['presenting', 'break_now'], true); ?>
                                <li class="list-group-item d-flex align-items-center gap-3<?= $now ? ' home-order-now' : '' ?><?= $slot['status'] === 'done' ? ' text-secondary' : '' ?>">
                                    <span class="home-order-time fw-bold"><?= $slotTime((string) $slot['planned_start_at']) ?></span>
                                    <?php if ($isBreak): ?>
                                        <?= icon('break.' . ($slot['break_type'] ?? 'other'), 'icon-lg text-orange') ?>
                                        <span class="fw-bold ml-text"><?= esc($slot['label']) ?></span>
                                    <?php else: ?>
                                        <?php if ($slot['thumb']): ?><img class="avatar avatar-sm avatar-photo" src="<?= esc($slot['thumb']) ?>" alt="" loading="lazy" width="32" height="32"><?php else: ?><span class="avatar avatar-sm"><?= icon('person.user') ?></span><?php endif ?>
                                        <span class="min-w-0"><span class="fw-bold ml-text d-block text-truncate"><?= esc($slot['presenter_name']) ?></span><span class="small text-secondary ml-text d-block text-truncate"><?= esc($slot['topic']) ?></span></span>
                                    <?php endif ?>
                                    <?php if ($now): ?><span class="badge bg-red-lt ms-auto"><?= $slot['status'] === 'presenting' ? 'Presenting now' : 'On now' ?></span><?php elseif ($slot['status'] === 'done'): ?><span class="badge bg-green-lt ms-auto">Done</span><?php endif ?>
                                </li>
                            <?php endforeach ?>
                        </ol>
                    <?php endif ?>
                    <div class="card-footer text-end"><a class="btn btn-sm" href="<?= site_url('slams/' . $s['slug']) ?>">Full details</a></div>
                </article>
            </div>
        <?php endforeach ?>
    </div>

    <?php if ($past !== []): ?>
        <h3 class="h4 mt-4">Earlier slams</h3>
        <div class="list-group">
            <?php foreach ($past as $s): ?>
                <a class="list-group-item list-group-item-action d-flex justify-content-between" href="<?= site_url('slams/' . $s['slug']) ?>"><span class="ml-text"><?= esc($s['name']) ?></span><span class="text-secondary"><?= esc(local_time($s['opens_at'], 'd M Y')) ?></span></a>
            <?php endforeach ?>
        </div>
    <?php endif ?>
</section>
<?= $this->endSection() ?>

<?= $this->section('beforefooter') ?>
<section class="site-partners" aria-label="Organisers and supporters">
    <div class="container-xl py-4 text-center">
        <img src="<?= base_url('brand/logos-final.webp') ?>" alt="Organisers and supporters of Kerala Science Slam" class="site-partners-img" width="1200" height="413" loading="lazy">
    </div>
</section>
<?= $this->endSection() ?>
