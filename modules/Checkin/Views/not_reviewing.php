<?php
/** @var array $slam  @var int $min  @var bool $isVolunteer  @var bool $readOnly */
$this->setVar('narrow', false);
?>
<?= $this->extend($isVolunteer ? 'Modules\Core\Views\layouts\volunteer' : 'Modules\Core\Views\layouts\admin') ?>
<?php if (! $isVolunteer): ?><?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'idle']) ?><?= $this->endSection() ?><?php endif ?>
<?= $this->section('content') ?>
<?php $sid = (int) $slam['id']; ?>
<div class="card card-accent accent-orange mb-3">
    <div class="card-body">
        <h2 class="h3 mb-1"><?= icon('status.warning', 'me-2 text-orange') ?>People who are not reviewing</h2>
        <p class="mb-2">Checked-in reviewers who skipped the last <strong><?= (int) $min ?></strong> finished reviews in a row. Reviews that closed before someone arrived never count against them, and a review that is still open is not counted. If someone has been inside but is not voting, you can check them out so they stop counting as present; they can be checked in again later from the desk.</p>
        <form id="idle-filters" class="d-flex flex-wrap align-items-center gap-2">
            <label class="form-label mb-0" for="idle-min">Skipped in a row</label>
            <select id="idle-min" name="min" class="form-select form-select-sm" style="width:auto">
                <?php foreach ([1, 2, 3, 4, 5] as $n): ?><option value="<?= $n ?>" <?= $n === (int) $min ? 'selected' : '' ?>><?= $n ?> or more</option><?php endforeach ?>
            </select>
            <?php if (! $readOnly): ?>
                <button type="button" class="btn btn-danger btn-sm ms-md-auto" data-api-post="<?= site_url('api/volunteer/idle-checkout') ?>?slam=<?= $sid ?>" data-form="#idle-filters" data-body='<?= esc(json_encode(['slam_id' => $sid]), 'attr') ?>'
                    data-confirm="Check out everyone listed here? They are signed out of their phones now and counted as not present. This cannot be undone, but each person can be checked in again from the desk."
                    data-reason data-reason-default="Not reviewing" data-danger data-title="Check out everyone listed" data-reload data-success="Checked out."><?= icon('action.logout') ?>Check out everyone listed</button>
            <?php endif ?>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-body">
        <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
            'endpoint' => 'idle-reviewers', 'filters' => ['slam_id' => $sid], 'filterForm' => '#idle-filters', 'order' => [[7, 'desc']], 'refresh' => 8000,
            'empty' => 'Nobody is idle: everyone checked in is keeping up.',
            'columns' => [
                ['data' => 'id', 'title' => '#', 'priority' => 50],
                ['data' => 'name', 'title' => 'Name', 'render' => 'ml', 'priority' => 1],
                ['data' => 'badge_id', 'title' => 'ID', 'priority' => 3],
                ['data' => 'mobile', 'title' => 'Mobile', 'priority' => 6],
                ['data' => 'user_type', 'title' => 'Type', 'render' => 'user_type', 'priority' => 7],
                ['data' => 'checked_in_at', 'title' => 'In since', 'render' => 'datetime', 'priority' => 5],
                ['data' => 'reviewed', 'title' => 'Reviewed', 'priority' => 4],
                ['data' => 'missed', 'title' => 'Skipped in a row', 'priority' => 2],
                ['data' => 'eligible', 'title' => 'Out of', 'priority' => 8],
                ['data' => 'last_review_at', 'title' => 'Last review', 'render' => 'datetime', 'priority' => 9],
            ],
            'actions' => $readOnly ? [] : [
                ['label' => 'Check out', 'icon' => 'action.logout', 'post' => '/api/force-checkout/{attendee_id}', 'reason' => true, 'reasonDefault' => 'Not reviewing', 'danger' => true, 'confirm' => 'Check out {name}?'],
            ],
        ]), 'attr') ?>'></table>
    </div>
</div>
<?= $this->endSection() ?>
