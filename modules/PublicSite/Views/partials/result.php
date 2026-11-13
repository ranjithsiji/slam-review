<?php
/** One published result card. @var array $rs the slam  @var list<array> $top  @var bool $link show a link to the results page */
$res = ['top' => $top];
?>
    <section class="card card-accent accent-orange mb-4" aria-labelledby="res-<?= esc($rs['slug'], 'attr') ?>">
        <div class="card-header d-block">
            <div class="text-secondary small text-uppercase fw-bold"><?= icon('nav.marks', 'icon-inline me-1') ?>Result</div>
            <h2 class="h2 m-0" id="res-<?= esc($rs['slug'], 'attr') ?>"><?= $rs['type'] === 'final' ? esc($rs['name']) . ': final result' : esc($rs['name']) . ': top ' . count($res['top']) . ' presenters' ?></h2>
            <div class="text-secondary small"><?= esc(local_time($rs['opens_at'], 'l, d F Y')) ?> · <?= esc($rs['venue_name']) ?><?= $rs['type'] === 'regional' ? ' · selected for the final' : '' ?></div>
            <?php if (! empty($link)): ?><a class="btn btn-sm mt-2" href="<?= site_url('slams/' . $rs['slug'] . '/results') ?>"><?= icon('nav.marks') ?>Results page</a><?php endif ?>
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
