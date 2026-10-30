<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'attendees']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="card mb-3">
    <div class="card-header"><h3 class="card-title">Step 2 of 2: review and confirm — <?= esc($batch['filename']) ?></h3></div>
    <div class="card-body">
        <?php if ($batch['status'] !== 'previewed'): ?>
            <div class="alert alert-info">This import is <?= esc($batch['status']) ?>.</div>
        <?php endif ?>
        <div class="row g-3 mb-3 text-center">
            <div class="col-4"><div class="h1 m-0 text-green"><?= (int) ($counts['new'] ?? 0) ?></div><div class="text-secondary">new</div></div>
            <div class="col-4"><div class="h1 m-0 text-orange"><?= (int) ($counts['duplicate'] ?? 0) ?></div><div class="text-secondary">already registered (<?= $batch['mode'] === 'skip_existing' ? 'skip' : 'update' ?>)</div></div>
            <div class="col-4"><div class="h1 m-0 text-red"><?= (int) ($counts['invalid'] ?? 0) ?></div><div class="text-secondary">invalid (not imported)</div></div>
        </div>
        <?php if ($duplicateOf): ?>
            <div class="alert alert-warning"><?= icon('status.warning', 'me-1') ?>This exact file was already imported for this slam (batch #<?= (int) $duplicateOf ?>). Tick the box below to import it again anyway.</div>
        <?php endif ?>
        <?php if ($checkedInWarn > 0): ?>
            <div class="alert alert-warning"><?= icon('status.warning', 'me-1') ?><?= $checkedInWarn ?> of the people being updated are checked in right now. Their name, email or type will change while they are at the venue.</div>
        <?php endif ?>
        <?php if (($counts['invalid'] ?? 0) + ($counts['duplicate'] ?? 0) > 0): ?>
            <a class="btn btn-sm" href="<?= site_url('admin/attendees/import/' . $batch['id'] . '/errors.csv') ?>"><?= icon('action.download') ?>Download error rows (CSV)</a>
        <?php endif ?>
    </div>
</div>
<?php if ($batch['status'] === 'previewed'): ?>
<div class="card">
    <div class="card-header">
        <form id="ir-filters" class="w-100"><select name="status" class="form-select" aria-label="Row status" style="max-width:260px">
            <option value="">All rows</option><option value="new">New</option><option value="duplicate">Already registered</option><option value="invalid">Invalid</option>
        </select></form>
    </div>
    <div class="card-body">
        <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
            'endpoint' => 'import-rows', 'filters' => ['batch_id' => (int) $batch['id']], 'filterForm' => '#ir-filters', 'order' => [[0, 'asc']],
            'columns' => [
                ['data' => 'line_no', 'title' => 'Line', 'priority' => 2],
                ['data' => 'status', 'title' => 'Status', 'render' => 'status', 'priority' => 1],
                ['data' => 'name', 'title' => 'Name', 'render' => 'ml', 'priority' => 3],
                ['data' => 'mobile', 'title' => 'Mobile', 'orderable' => false, 'priority' => 5],
                ['data' => 'email', 'title' => 'Email', 'orderable' => false, 'priority' => 7],
                ['data' => 'user_type', 'title' => 'Type', 'orderable' => false, 'priority' => 6],
                ['data' => 'badge_id', 'title' => 'ID', 'orderable' => false, 'priority' => 8],
                ['data' => 'error', 'title' => 'Message', 'orderable' => false, 'priority' => 4],
            ],
        ]), 'attr') ?>'></table>
    </div>
</div>
<div class="sticky-actions d-flex flex-wrap gap-2 justify-content-end mt-3">
    <form method="post" action="<?= site_url('admin/attendees/import/cancel/' . $batch['id']) ?>"><?= csrf_field() ?><button class="btn btn-link" type="submit">Cancel import</button></form>
    <form method="post" action="<?= site_url('admin/attendees/import/commit/' . $batch['id']) ?>" class="d-flex gap-2 align-items-center" data-confirm="Import <?= (int) ($counts['new'] ?? 0) ?> new attendee(s)<?= $batch['mode'] === 'update_existing' ? ' and update ' . (int) ($counts['duplicate'] ?? 0) : '' ?>? Everything is saved in one go, or nothing.">
        <?= csrf_field() ?>
        <?php if ($duplicateOf): ?><label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="confirm_reimport" value="1" required><span class="form-check-label">Import again</span></label><?php endif ?>
        <button class="btn btn-primary" type="submit"><?= icon('status.ok') ?>Confirm import</button>
    </form>
</div>
<?php endif ?>
<?= $this->endSection() ?>
