<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?>
<div class="page-header"><div class="container-xl"><div class="row g-2 align-items-center">
    <div class="col"><h2 class="page-title">QR codes &amp; badges</h2><div class="text-secondary">Badge QR codes open <code><?= esc(preg_replace('#/checkin$#', '', $typeUrl)) ?>/in/ID</code>; check-in still needs a button press.</div></div>
    <div class="col-12 col-md-4">
        <form method="get"><select name="slam" class="form-select" data-autosubmit aria-label="Slam">
            <?php foreach ($slams as $s): ?><option value="<?= $s['id'] ?>" <?= (int) $s['id'] === $slamId ? 'selected' : '' ?>><?= esc($s['name']) ?></option><?php endforeach ?>
        </select><noscript><button class="btn mt-1">Show</button></noscript></form>
    </div>
</div></div></div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php if (! $slamId): ?><div class="empty"><p class="empty-title">Create a slam first.</p></div><?php else: ?>
<div class="row row-cards">
    <div class="col-12 col-lg-6">
        <div class="card h-100 card-accent accent-blue">
            <div class="card-header"><h3 class="card-title"><?= icon('qr.code', 'me-1') ?>Single attendee</h3></div>
            <div class="card-body">
                <label class="form-label" for="qr-q">Search by name, mobile or ID</label>
                <input id="qr-q" class="form-control" autocomplete="off" data-qr-search="<?= site_url('api/admin/attendee-lookup?slam=' . $slamId) ?>">
                <div class="list-group list-group-flush mt-2" data-qr-results></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <form class="card h-100 card-accent accent-green" method="post" action="<?= site_url('admin/qr/batch') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="slam_id" value="<?= $slamId ?>">
            <div class="card-header"><h3 class="card-title"><?= icon('action.print', 'me-1') ?>Badge PDF (A4)</h3></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="filter">Who</label>
                    <select id="filter" name="filter" class="form-select">
                        <option value="all">All active attendees</option><option value="user">Users only</option><option value="judge">Judges only</option>
                        <option value="not_printed">Not printed yet</option><option value="batch">One import batch</option><option value="selected">Hand-picked (from the search)</option>
                    </select>
                </div>
                <div class="mb-3"><label class="form-label" for="batch_id">Import batch</label>
                    <select id="batch_id" name="batch_id" class="form-select"><option value="">—</option><?php foreach ($batches as $b): ?><option value="<?= $b['id'] ?>">#<?= $b['id'] ?> <?= esc($b['filename']) ?> (<?= esc(local_time($b['created_at'], 'd M')) ?>)</option><?php endforeach ?></select></div>
                <input type="hidden" name="ids" data-qr-picked>
                <div class="mb-2 small" data-qr-picked-label></div>
                <div class="mb-3"><label class="form-label" for="preset">Badge size</label>
                    <select id="preset" name="preset" class="form-select"><?php foreach ($presets as $k => $p): ?><option value="<?= $k ?>"><?= esc($p[0]) ?></option><?php endforeach ?></select></div>
                <label class="form-check"><input class="form-check-input" type="checkbox" name="cut_marks" value="1" checked><span class="form-check-label">Cut marks</span></label>
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary" type="submit"><?= icon('action.download') ?>Generate PDF</button></div>
        </form>
    </div>
    <div class="col-12 col-md-6">
        <div class="card h-100 card-accent accent-orange">
            <div class="card-header"><h3 class="card-title">Entrance poster</h3></div>
            <div class="card-body">
                <p class="text-secondary">A generic QR to <code><?= esc($typeUrl) ?></code> for people who lost their badge QR or want to type the ID.</p>
                <a class="btn" href="<?= site_url('admin/qr/entrance.pdf?format=A4&slam=' . $slamId) ?>"><?= icon('action.download') ?>A4 PDF</a>
                <a class="btn" href="<?= site_url('admin/qr/entrance.pdf?format=A3&slam=' . $slamId) ?>"><?= icon('action.download') ?>A3 PDF</a>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card h-100 card-accent accent-blue">
            <div class="card-header"><h3 class="card-title">Public schedule link</h3></div>
            <div class="card-body d-flex gap-3 align-items-center">
                <div class="qr-box" style="width:120px"><img src="<?= site_url('admin/qr/public/' . $slamId . '.svg') ?>" alt="QR code for the public schedule"></div>
                <div class="flex-fill">
                    <input class="form-control mb-2" readonly value="<?= esc((string) $publicUrl) ?>" aria-label="Public schedule link">
                    <button class="btn btn-sm" type="button" data-copy="<?= esc((string) $publicUrl, 'attr') ?>"><?= icon('action.copy') ?>Copy</button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif ?>
<?= $this->endSection() ?>
