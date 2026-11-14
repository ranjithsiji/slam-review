<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'finalists']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php /** @var array $slam @var list<array{name: string, designation: string, photo: ?string}> $people */ ?>
<?php
$mlMonths = ['ജനുവരി', 'ഫെബ്രുവരി', 'മാർച്ച്', 'ഏപ്രിൽ', 'മേയ്', 'ജൂൺ', 'ജൂലൈ', 'ഓഗസ്റ്റ്', 'സെപ്റ്റംബർ', 'ഒക്ടോബർ', 'നവംബർ', 'ഡിസംബർ'];
$mlDate = $mlMonths[(int) local_time($slam['opens_at'], 'n') - 1] . ' ' . local_time($slam['opens_at'], 'j');
$districts = ['thiruvananthapuram' => 'തിരുവനന്തപുരം', 'kollam' => 'കൊല്ലം', 'pathanamthitta' => 'പത്തനംതിട്ട', 'alappuzha' => 'ആലപ്പുഴ',
    'kottayam' => 'കോട്ടയം', 'idukki' => 'ഇടുക്കി', 'ernakulam' => 'എറണാകുളം', 'thrissur' => 'തൃശ്ശൂർ', 'palakkad' => 'പാലക്കാട്',
    'malappuram' => 'മലപ്പുറം', 'kozhikode' => 'കോഴിക്കോട്', 'wayanad' => 'വയനാട്', 'kannur' => 'കണ്ണൂർ', 'kasaragod' => 'കാസർഗോഡ്'];
$mlRegion = '';
foreach ($districts as $en => $ml) {
    if (stripos($slam['slug'] . ' ' . $slam['name'], $en) !== false) { $mlRegion = $ml . ' റീജിയൺ സയൻസ് സ്ലാം'; break; }
}
?>
<?php if ($people === []): ?>
    <div class="alert alert-info">Choose the finalists first, on the <a href="<?= site_url('admin/slams/' . (int) $slam['id'] . '/finalists') ?>">Finalists</a> screen.</div>
<?php else: ?>
<div class="card" data-poster='<?= esc(json_encode([
    'people' => $people, 'logo' => base_url('brand/slam-logo.svg'), 'logoLight' => base_url('brand/slam-logo-on-light.svg'), 'logoMain' => base_url('brand/kss-logo-2026.png'), 'partners' => base_url('brand/logos-final.webp'), 'slam' => $slam['name'],
    'venue' => trim(($slam['venue_name'] ?? '') . ''), 'date' => local_time($slam['opens_at'], 'j F Y'), 'file' => 'finalists-' . $slam['slug'],
], JSON_UNESCAPED_UNICODE), 'attr') ?>'>
    <div class="card-header d-flex flex-wrap gap-2 align-items-center">
        <h3 class="card-title me-auto">Finalists poster</h3>
        <label class="form-label mb-0 small" for="poster-who">Poster</label>
        <select id="poster-who" class="form-select form-select-sm" style="width:auto;max-width:16rem" data-poster-who>
            <option value="-1">Everyone together</option>
            <?php foreach ($people as $i => $p): ?>
                <option value="<?= (int) $i ?>"><?= esc($p['name']) ?> only</option>
            <?php endforeach ?>
        </select>
        <span class="d-inline-flex gap-2 align-items-center" data-poster-style-wrap hidden>
            <label class="form-label mb-0 small" for="poster-style">Style</label>
            <select id="poster-style" class="form-select form-select-sm" style="width:auto" data-poster-style>
                <option value="light">Light card</option>
                <option value="dark">Dark</option>
            </select>
        </span>
        <label class="form-label mb-0 small" for="poster-size">Size</label>
        <select id="poster-size" class="form-select form-select-sm" style="width:auto" data-poster-size>
            <option value="1080x1350">Instagram / Facebook post 1080 × 1350</option>
            <option value="1080x1920">Story / WhatsApp status 1080 × 1920</option>
            <option value="1080x1080">Square post 1080 × 1080</option>
            <option value="1200x630">Facebook / link share 1200 × 630</option>
            <option value="1920x1080">Screen 1920 × 1080</option>
            <option value="2480x3508">A4 print (300 dpi)</option>
        </select>
        <span class="d-inline-flex gap-2 align-items-center" data-poster-title-wrap>
            <label class="form-label mb-0 small" for="poster-title">Heading</label>
            <input id="poster-title" class="form-control form-control-sm" style="width:16rem" value="Selected for the Final" data-poster-title>
        </span>
        <button type="button" class="btn btn-success btn-sm" data-poster-share><?= icon('action.share') ?>Share</button>
        <button type="button" class="btn btn-primary btn-sm" data-poster-png><?= icon('action.download') ?>Download PNG</button>
        <button type="button" class="btn btn-sm" data-poster-each><?= icon('action.download') ?>Download one per person (<?= count($people) ?>)</button>
    </div>
    <div class="card-body border-bottom row g-2" data-poster-light hidden>
        <div class="col-6 col-md-2"><label class="form-label small mb-1" for="pl-date">Date</label><input id="pl-date" class="form-control form-control-sm" value="<?= esc($mlDate, 'attr') ?>" data-poster-date></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1" for="pl-region">Slam</label><input id="pl-region" class="form-control form-control-sm" value="<?= esc($mlRegion !== '' ? $mlRegion : $slam['name'], 'attr') ?>" data-poster-region></div>
        <div class="col-12 col-md-4"><label class="form-label small mb-1" for="pl-heading">Heading</label><input id="pl-heading" class="form-control form-control-sm" value="ഫൈനൽ സയൻസ് സ്ലാമിലേക്ക് തെരഞ്ഞെടുക്കപ്പെട്ടവർ" data-poster-heading></div>
        <div class="col-12 col-md-3"><label class="form-label small mb-1" for="pl-tag">Under the logo</label><input id="pl-tag" class="form-control form-control-sm" value="സയൻസ് ജനങ്ങളിലേക്ക്" data-poster-tagline></div>
    </div>
    <div class="card-body text-center" style="background:#e9ecef">
        <canvas data-poster-canvas style="max-width:100%;max-height:75vh;box-shadow:0 4px 18px rgba(0,0,0,.25)"></canvas>
        <p class="small text-secondary mt-2 mb-0">Choose a name under Poster for that person's own poster. Photos come from the presenters' profiles. A presenter without a photo gets their initials.</p>
    </div>
</div>
<?php endif ?>
<?= $this->endSection() ?>
