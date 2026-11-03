<?php
/** @var list<array> $coming  @var list<array> $past  @var list<array> $live */
$this->setVar('title', 'Kerala Science Slam');
$sig = static fn (array $l) => implode('|', array_map(static fn ($d) => $d['slug'] . ':' . ($d['now']['id'] ?? '') . ':' . ($d['next']['id'] ?? '') . ':' . $d['phase'] . ':' . (int) $d['paused'] . ':' . count($d['ads'] ?? []) . ':' . ($d['talk']['title'] ?? ''), $l));
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
                    <a class="btn btn-sm" href="<?= site_url('slams/' . $d['slug'] . '/tv') ?>" target="_blank" rel="noopener">TV screen</a>
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

<?php
// Regional slams shown as cards. Earlier or extra slams not listed here stay in the plain list below.
$regional = [
    'ernakulam' => ['date' => 'നവംബർ 7', 'venue' => 'മഹാരാജാസ് കോളേജ് (ഗവ. ഓട്ടോണമസ്), എറണാകുളം', 'place' => 'എറണാകുളം'],
    'kollam'    => ['date' => 'നവംബർ 14', 'venue' => 'ഫാത്തിമ മാതാ നാഷണൽ കോളേജ് (ഓട്ടോണമസ്), കൊല്ലം', 'place' => 'കൊല്ലം'],
    'thrissur'  => ['date' => 'നവംബർ 21', 'venue' => 'സെന്റ് മേരീസ് കോളേജ് (ഓട്ടോണമസ്), തൃശ്ശൂർ', 'place' => 'തൃശ്ശൂർ'],
    'kannur'    => ['date' => 'നവംബർ 28', 'venue' => 'പയ്യന്നൂർ കോളേജ്, കണ്ണൂർ', 'place' => 'കണ്ണൂർ'],
];
$all       = [...$coming, ...$past];
$published = array_column($all, null, 'slug');
$regional  = array_intersect_key($regional, $published);
$final     = array_values(array_filter($all, static fn (array $s): bool => $s['type'] === 'final'))[0] ?? null;
$others    = array_filter($past, static fn (array $s): bool => ! isset($regional[$s['slug']]) && $s['type'] !== 'final');
?>
<?php if ($coming === [] && $past === []): ?>
    <div class="empty"><p class="empty-title">The schedule will appear here once it is published.</p></div>
<?php endif ?>
<?php if ($others !== []): ?>
    <section class="mb-4" aria-labelledby="earlier-h">
        <h2 class="h4" id="earlier-h">Earlier slams</h2>
        <div class="list-group">
            <?php foreach ($others as $s): ?>
                <a class="list-group-item list-group-item-action" href="<?= site_url('slams/' . $s['slug']) ?>"><?= esc($s['name']) ?></a>
            <?php endforeach ?>
        </div>
    </section>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('beforefooter') ?>
<?php if ($regional !== []): ?>
    <section class="home-band home-band-orange" aria-labelledby="regional-h">
        <div class="container-xl">
            <h2 class="home-band-title ml-text" id="regional-h"><?= count($regional) ?> റിജിയണൽ സ്ലാമുകൾ</h2>
            <p class="home-band-lead ml-text">നാലു റീജിയണൽ മത്സരവും അതിൽനിന്നു തെരഞ്ഞെടുക്കപ്പെടുന്നവർ പങ്കെടുക്കുന്ന സംസ്ഥാനതല മത്സരവും</p>
            <div class="home-regions">
                <?php foreach ($regional as $slug => $r): ?>
                    <a class="home-region" href="<?= site_url('slams/' . $slug . '/presenters') ?>">
                        <img src="<?= base_url('brand/slams/' . $slug . '-cover.webp') ?>" alt="" loading="lazy">
                        <span class="home-region-text ml-text">
                            <strong><?= esc($r['date']) ?></strong>
                            <span><?= esc($r['venue']) ?></span>
                            <span class="home-region-go" aria-hidden="true">&gt;&gt;&gt;</span>
                            <span class="visually-hidden">Presenters</span>
                        </span>
                    </a>
                <?php endforeach ?>
            </div>
        </div>
    </section>
<?php endif ?>
<section class="home-band home-band-orange" aria-labelledby="final-h">
    <div class="container-xl">
        <h2 class="home-band-title ml-text" id="final-h">ഫൈനൽ മത്സരം</h2>
        <p class="home-band-lead ml-text">ഡിസംബർ 12 ന് നാലു റിജിയണൽ മത്സരങ്ങളിൽ നിന്നുമുള്ള മികച്ച അവതരണങ്ങൾ മാറ്റുരക്കും</p>
        <div class="home-final">
            <img src="<?= base_url('brand/slams/final-cover.webp') ?>" alt="" loading="lazy">
            <div class="home-final-text ml-text">
                <strong>ഫാറൂഖ് കോളേജ് കോഴിക്കോട്</strong>
                <a class="home-final-btn" href="<?= $final !== null ? site_url('slams/' . $final['slug']) : 'https://scienceslam.in/final-calicut/' ?>"<?= $final !== null ? '' : ' target="_blank" rel="noopener"' ?>>വിശദവിവരങ്ങൾ</a>
            </div>
        </div>
    </div>
</section>
<?php if ($regional !== []): ?>
    <section class="home-schedule" aria-labelledby="schedule-h">
        <h2 class="home-schedule-title ml-text" id="schedule-h">സമയക്രമം</h2>
        <div class="container-xl">
            <div class="home-schedule-row">
                <?php foreach ($regional as $slug => $r): ?>
                    <div class="home-schedule-item">
                        <img src="<?= base_url('brand/slams/' . $slug . '-logo.webp') ?>" alt="" loading="lazy">
                        <h3 class="ml-text"><?= esc($r['date']) ?></h3>
                        <p class="ml-text"><?= esc($r['place']) ?></p>
                        <a class="btn" href="<?= site_url('slams/' . $slug) ?>">Schedule <span aria-hidden="true">→</span></a>
                    </div>
                <?php endforeach ?>
            </div>
        </div>
    </section>
<?php endif ?>
<section class="site-partners" aria-label="Organisers and supporters">
    <div class="container-xl py-4 text-center">
        <img src="<?= base_url('brand/logos-final.webp') ?>" alt="Organisers and supporters of Kerala Science Slam" class="site-partners-img" width="1200" height="413" loading="lazy">
    </div>
</section>
<?= $this->endSection() ?>
