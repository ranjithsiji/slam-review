<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'checkins']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-primary" href="<?= site_url('volunteer?slam=' . $slam['id']) ?>"><?= icon('qr.scan') ?>Open desk (scan / search)</a>
    <a class="btn" href="#" id="ci-export"><?= icon('action.export') ?>Export CSV</a>
</div>
<?php
$tones = ['blue', 'green', 'orange', 'purple', 'teal', 'pink'];
$totalIn = array_sum(array_column($cats, 'inside'));
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <h3 class="m-0 me-auto"><?= icon('nav.checkins', 'me-1') ?>Judges inside now, by category: <?= (int) $totalIn ?></h3>
    <span class="small text-secondary">As of <?= esc(local_time(\Modules\Core\Libraries\Clock::db(), 'H:i:s')) ?></span>
    <a class="btn btn-sm" href="<?= current_url() ?>"><?= icon('action.refresh') ?>Refresh</a>
</div>
<div class="row row-cards mb-3">
    <?php $i = 0; foreach ($cats as $c): $tone = $tones[$i++ % count($tones)]; ?>
        <div class="col-6 col-md-4 col-xl">
            <div class="card card-sm card-accent-left accent-<?= $tone ?> h-100"><div class="card-body">
                <div class="text-secondary small text-uppercase fw-bold ml-text"><?= esc($c['label']) ?></div>
                <div class="h1 m-0"><?= (int) $c['inside'] ?> <span class="fs-4 text-secondary fw-normal">inside</span></div>
                <div class="small text-secondary"><?= (int) $c['ever'] ?> checked in today · <?= (int) $c['registered'] ?> registered</div>
                <div class="progress progress-sm mt-2"><div class="progress-bar bg-<?= $tone ?>" style="width:<?= $totalIn > 0 ? round($c['inside'] * 100 / $totalIn) : 0 ?>%"></div></div>
                <div class="small text-secondary mt-1"><?= $totalIn > 0 ? round($c['inside'] * 100 / $totalIn) : 0 ?>% of judges inside</div>
            </div></div>
        </div>
    <?php endforeach ?>
    <div class="col-6 col-md-4 col-xl">
        <div class="card card-sm card-accent-left accent-indigo h-100"><div class="card-body">
            <div class="text-secondary small text-uppercase fw-bold"><?= esc($experts['label']) ?></div>
            <div class="h1 m-0"><?= (int) $experts['inside'] ?> <span class="fs-4 text-secondary fw-normal">inside</span></div>
            <div class="small text-secondary"><?= (int) $experts['ever'] ?> checked in today · <?= (int) $experts['registered'] ?> registered</div>
            <div class="small text-secondary mt-1">Not part of the audience representative draw</div>
        </div></div>
    </div>
</div>
<div class="card">
    <div class="card-header">
        <form id="ci-filters"><select name="open" class="form-select" aria-label="Show" style="max-width:240px">
            <option value="">All check-ins</option><option value="1">Currently inside</option><option value="0">Checked out</option>
        </select></form>
    </div>
    <div class="card-body">
        <table class="table table-vcenter w-100" data-dt='<?= esc(json_encode([
            'endpoint' => 'checkins', 'filters' => ['slam_id' => (int) $slam['id']], 'filterForm' => '#ci-filters', 'exportLink' => '#ci-export',
            'order' => [[0, 'desc']], 'refresh' => 8000,
            'columns' => [
                ['data' => 'id', 'title' => '#', 'priority' => 50],
                ['data' => 'name', 'title' => 'Name', 'render' => 'ml', 'priority' => 1],
                ['data' => 'badge_id', 'title' => 'ID', 'priority' => 3],
                ['data' => 'user_type', 'title' => 'Type', 'render' => 'user_type', 'priority' => 5],
                ['data' => 'checked_in_at', 'title' => 'In', 'render' => 'datetime', 'priority' => 4],
                ['data' => 'checked_out_at', 'title' => 'Out', 'render' => 'datetime', 'priority' => 6],
                ['data' => 'checkout_type', 'title' => 'Checkout', 'priority' => 7],
                ['data' => 'by', 'title' => 'By', 'orderable' => false, 'priority' => 9],
                ['data' => 'claimed', 'title' => 'Phone linked', 'render' => 'bool', 'orderable' => false, 'priority' => 8],
                ['data' => 'late', 'title' => 'Late entry', 'render' => 'bool', 'orderable' => false, 'priority' => 10],
            ],
            'actions' => [
                ['label' => 'Force check-out', 'icon' => 'action.logout', 'post' => '/api/force-checkout/{attendee_id}', 'reason' => true, 'danger' => true, 'when' => '!checked_out_at'],
                ['label' => 'Timeline', 'icon' => 'nav.log', 'href' => '/admin/activity-log?target_type=attendee&target_id={attendee_id}'],
            ],
        ]), 'attr') ?>'></table>
    </div>
</div>
<?= $this->endSection() ?>
