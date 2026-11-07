<?php /** Judges inside now per audience category (and experts when given). @var array $cats @var array|null $experts */ ?>
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
    <?php if (! empty($experts)): ?>
    <div class="col-6 col-md-4 col-xl">
        <div class="card card-sm card-accent-left accent-indigo h-100"><div class="card-body">
            <div class="text-secondary small text-uppercase fw-bold"><?= esc($experts['label']) ?></div>
            <div class="h1 m-0"><?= (int) $experts['inside'] ?> <span class="fs-4 text-secondary fw-normal">inside</span></div>
            <div class="small text-secondary"><?= (int) $experts['ever'] ?> checked in today · <?= (int) $experts['registered'] ?> registered</div>
            <div class="small text-secondary mt-1">Not part of the audience representative draw</div>
        </div></div>
    </div>
<?php endif ?>
</div>
