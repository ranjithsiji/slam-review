<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?>
<div class="page-header"><div class="container-xl"><div class="row g-2 align-items-center">
    <div class="col"><h2 class="page-title">Activity log</h2><div class="text-secondary">Append-only record of every action. Filter by slam and time first on busy days.</div></div>
    <div class="col-auto"><a class="btn" href="#" id="al-export"><?= icon('action.export') ?>Export CSV</a></div>
</div></div></div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="card">
    <div class="card-header">
        <form id="al-filters" class="row g-2 w-100">
            <div class="col-12 col-md-3"><select name="slam_id" class="form-select" aria-label="Slam"><option value="">All slams</option>
                <?php foreach ($slams as $s): ?><option value="<?= $s['id'] ?>" <?= (string) ($preset['slam_id'] ?? '') === (string) $s['id'] ? 'selected' : '' ?>><?= esc($s['name']) ?></option><?php endforeach ?></select></div>
            <div class="col-6 col-md-2"><select name="actor_type" class="form-select" aria-label="Actor type"><option value="">Any actor</option>
                <?php foreach (['admin', 'volunteer', 'user', 'judge', 'system', 'anonymous'] as $t): ?><option <?= ($preset['actor_type'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach ?></select></div>
            <div class="col-6 col-md-2"><select name="outcome" class="form-select" aria-label="Outcome"><option value="">Any outcome</option><option>success</option><option>failure</option><option>denied</option></select></div>
            <div class="col-12 col-md-5"><select name="action[]" class="form-select" multiple size="1" aria-label="Actions">
                <?php foreach (['auth.*', 'checkin.*', 'checkout.*', 'review.*', 'revision.*', 'window.*', 'schedule.*', 'slam.*', 'phase.*', 'marks.*', 'attendee.*', 'badge.*', 'question.*', 'system.*'] as $a): ?>
                    <option value="<?= $a ?>" <?= ($preset['action'] ?? '') === $a ? 'selected' : '' ?>><?= $a ?></option>
                <?php endforeach ?></select></div>
            <div class="col-6 col-md-3"><label class="form-label small mb-0" for="al-from">From (IST)</label><input id="al-from" type="datetime-local" class="form-control" data-ist-to="from"></div>
            <div class="col-6 col-md-3"><label class="form-label small mb-0" for="al-to">To (IST)</label><input id="al-to" type="datetime-local" class="form-control" data-ist-to="to"></div>
            <input type="hidden" name="from"><input type="hidden" name="to">
            <?php foreach (['target_type', 'target_id', 'actor_id', 'ip'] as $k): if (isset($preset[$k])): ?><input type="hidden" name="<?= $k ?>" value="<?= esc((string) $preset[$k]) ?>"><?php endif; endforeach ?>
            <div class="col-12 col-md-6 d-flex align-items-end gap-2">
                <?php if (isset($preset['target_type'])): ?><span class="badge bg-blue-lt">Timeline: <?= esc($preset['target_type']) ?> #<?= esc((string) ($preset['target_id'] ?? '')) ?></span><a class="btn btn-sm" href="<?= site_url('admin/activity-log') ?>">Clear</a><?php endif ?>
                <label class="form-check form-switch ms-auto mb-0"><input class="form-check-input" type="checkbox" data-live-tail><span class="form-check-label">Live tail</span></label>
            </div>
        </form>
    </div>
    <div class="card-body">
        <table id="activity-log" class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
            'endpoint' => 'activity-log', 'filterForm' => '#al-filters', 'exportLink' => '#al-export', 'order' => [[0, 'desc']],
            'columns' => [
                ['data' => 'id', 'title' => '#', 'priority' => 3],
                ['data' => 'occurred_at', 'title' => 'When (IST)', 'render' => 'datetime', 'priority' => 1],
                ['data' => 'actor_type', 'title' => 'Actor', 'priority' => 5],
                ['data' => 'actor_label', 'title' => 'Who', 'priority' => 4],
                ['data' => 'action', 'title' => 'Action', 'priority' => 2],
                ['data' => 'outcome', 'title' => 'Outcome', 'render' => 'outcome', 'priority' => 6],
                ['data' => 'reason', 'title' => 'Reason', 'orderable' => false, 'priority' => 7],
                ['data' => 'target_type', 'title' => 'Target', 'orderable' => false, 'priority' => 9],
                ['data' => 'target_id', 'title' => 'Target #', 'orderable' => false, 'priority' => 10],
                ['data' => 'ip', 'title' => 'IP', 'orderable' => false, 'priority' => 11],
                ['data' => 'meta', 'title' => 'Details', 'render' => 'json', 'orderable' => false, 'priority' => 8],
            ],
        ]), 'attr') ?>'></table>
    </div>
</div>
<div class="offcanvas offcanvas-end" tabindex="-1" id="log-detail" aria-labelledby="log-detail-title">
    <div class="offcanvas-header"><h2 class="offcanvas-title" id="log-detail-title">Entry</h2><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button></div>
    <div class="offcanvas-body"><pre class="small" data-log-detail style="white-space:pre-wrap"></pre></div>
</div>
<?= $this->endSection() ?>
