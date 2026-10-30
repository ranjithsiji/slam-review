<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'marks']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-3 align-items-center">
        <div class="me-auto">
            <?= $official ? status_badge('marks', 'official') : status_badge('marks', 'provisional') ?>
            <?php if ($run): ?><span class="text-secondary ms-2">Run #<?= (int) $run['id'] ?> · <?= esc(ist($run['calculated_at'], 'd M H:i')) ?><?= (int) $run['is_final'] ? ' · final' : '' ?></span>
            <?php else: ?><span class="text-secondary ms-2">Computed live from the current answers. Official once a marks run exists (after Slam Close).</span><?php endif ?>
            <div class="small text-secondary mt-1">Weights: users <?= (float) $weights['w_user'] ?> · judges <?= (float) $weights['w_judge'] ?> · complete reviewers <?= (float) $weights['w_complete'] ?> (groups without votes drop out and the rest are re-normalised).</div>
        </div>
        <div class="btn-list">
            <?php if (in_array($slam['status'], ['closed', 'marks_calculated'], true)): ?>
                <button class="btn btn-primary" data-api-post="<?= site_url('api/slams/' . $slam['id'] . '/marks/calculate') ?>" data-reason data-title="<?= $run ? 'Recalculate marks' : 'Calculate marks' ?>" data-confirm="Creates a new immutable snapshot; older runs stay for audit." data-reload><?= icon('action.refresh') ?><?= $run ? 'Recalculate' : 'Calculate marks' ?></button>
            <?php endif ?>
            <a class="btn" href="#" id="marks-export"><?= icon('action.export') ?>Export</a>
            <a class="btn" href="<?= site_url('admin/slams/' . $slam['id'] . '/mark-config') ?>"><?= icon('nav.settings') ?>Weights</a>
        </div>
    </div>
</div>
<div class="card mb-3">
    <div class="card-header">
        <form id="marks-filters" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="slam_id" value="<?= (int) $slam['id'] ?>">
            <label class="form-label mb-0" for="run_id">Show</label>
            <select id="run_id" name="run_id" class="form-select" style="max-width:260px">
                <?php if ($run): ?><option value="current">Current run (#<?= (int) $run['id'] ?>)</option><?php endif ?>
                <option value="provisional" <?= $run ? '' : 'selected' ?>>Live provisional values</option>
            </select>
        </form>
    </div>
    <div class="card-body">
        <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
            'endpoint' => 'marks', 'filterForm' => '#marks-filters', 'exportLink' => '#marks-export', 'order' => [[0, 'asc']], 'pii' => false,
            'columns' => [
                ['data' => 'rank', 'title' => 'Rank', 'priority' => 1],
                ['data' => 'presenter_name', 'title' => 'Presenter', 'render' => 'ml', 'priority' => 2],
                ['data' => 'topic', 'title' => 'Topic', 'render' => 'ml', 'priority' => 7],
                ['data' => 'avg_user', 'title' => 'Users', 'render' => 'number2', 'priority' => 4],
                ['data' => 'avg_judge', 'title' => 'Judges', 'render' => 'number2', 'priority' => 5],
                ['data' => 'avg_complete', 'title' => 'Complete', 'render' => 'number2', 'priority' => 6],
                ['data' => 'final_mark', 'title' => 'Final /10', 'render' => 'number2', 'priority' => 3],
                ['data' => 'n_user', 'title' => 'n users', 'priority' => 8],
                ['data' => 'n_judge', 'title' => 'n judges', 'priority' => 9],
                ['data' => 'n_complete', 'title' => 'n complete', 'priority' => 10],
            ],
        ]), 'attr') ?>'></table>
    </div>
</div>
<div class="card">
    <div class="card-header"><h3 class="card-title">Mark runs</h3></div>
    <div class="card-body">
        <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
            'endpoint' => 'mark-runs', 'filters' => ['slam_id' => (int) $slam['id']], 'order' => [[0, 'desc']], 'pii' => false, 'empty' => 'No runs yet.',
            'columns' => [
                ['data' => 'id', 'title' => '#', 'priority' => 1],
                ['data' => 'calculated_at', 'title' => 'When', 'render' => 'datetime', 'priority' => 2],
                ['data' => 'by', 'title' => 'By', 'orderable' => false, 'priority' => 4],
                ['data' => 'is_current', 'title' => 'Current', 'render' => 'bool', 'orderable' => false, 'priority' => 3],
                ['data' => 'is_final', 'title' => 'Final', 'render' => 'bool', 'orderable' => false, 'priority' => 5],
                ['data' => 'note', 'title' => 'Note', 'orderable' => false, 'priority' => 6],
            ],
            'actions' => [['label' => 'Open with differences', 'icon' => 'action.open', 'href' => '/admin/mark-runs/{id}']],
        ]), 'attr') ?>'></table>
    </div>
</div>
<?= $this->endSection() ?>
