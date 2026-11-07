<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'finalists']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php /** @var array $slam @var list<array{name: string, designation: string, photo: ?string}> $people */ ?>
<?php if ($people === []): ?>
    <div class="alert alert-info">Choose the finalists first, on the <a href="<?= site_url('admin/slams/' . (int) $slam['id'] . '/finalists') ?>">Finalists</a> screen.</div>
<?php else: ?>
<div class="card" data-poster='<?= esc(json_encode([
    'people' => $people, 'logo' => base_url('brand/slam-logo.svg'), 'slam' => $slam['name'],
    'venue' => trim(($slam['venue_name'] ?? '') . ''), 'date' => local_time($slam['opens_at'], 'j F Y'), 'file' => 'finalists-' . $slam['slug'],
], JSON_UNESCAPED_UNICODE), 'attr') ?>'>
    <div class="card-header d-flex flex-wrap gap-2 align-items-center">
        <h3 class="card-title me-auto">Finalists poster</h3>
        <label class="form-label mb-0 small" for="poster-size">Size</label>
        <select id="poster-size" class="form-select form-select-sm" style="width:auto" data-poster-size>
            <option value="1080x1350">Social media 1080 × 1350</option>
            <option value="1080x1080">Square 1080 × 1080</option>
            <option value="1920x1080">Screen 1920 × 1080</option>
            <option value="2480x3508">A4 print (300 dpi)</option>
        </select>
        <label class="form-label mb-0 small" for="poster-title">Heading</label>
        <input id="poster-title" class="form-control form-control-sm" style="width:16rem" value="Selected for the Final" data-poster-title>
        <button type="button" class="btn btn-primary btn-sm" data-poster-png><?= icon('action.download') ?>Download PNG</button>
    </div>
    <div class="card-body text-center" style="background:#e9ecef">
        <canvas data-poster-canvas style="max-width:100%;max-height:75vh;box-shadow:0 4px 18px rgba(0,0,0,.25)"></canvas>
        <p class="small text-secondary mt-2 mb-0">Photos come from the presenters' profiles. A presenter without a photo gets their initials.</p>
    </div>
</div>
<?php endif ?>
<?= $this->endSection() ?>
