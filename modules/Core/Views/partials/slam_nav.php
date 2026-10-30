<?php
/** Slam sub-navigation. @var array $slam  @var string $active */
$id    = (int) $slam['id'];
$items = [
    'overview'   => ['Overview', "admin/slams/{$id}", 'nav.slams'],
    'control'    => ['Control', "admin/slams/{$id}/control", 'nav.control'],
    'schedule'   => ['Schedule', "admin/slams/{$id}/schedule", 'nav.schedule'],
    'presenters' => ['Presenters', "admin/slams/{$id}/presenters", 'nav.presenters'],
    'attendees'  => ['Attendees', "admin/slams/{$id}/attendees", 'nav.attendees'],
    'checkins'   => ['Check-ins', "admin/slams/{$id}/checkins", 'nav.checkins'],
    'questions'  => ['Questions', "admin/slams/{$id}/questions", 'nav.questions'],
    'marks'      => ['Marks', "admin/slams/{$id}/marks", 'nav.marks'],
    'stats'      => ['Statistics', "admin/slams/{$id}/stats", 'nav.stats'],
];
?>
<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle"><?= esc(ucfirst($slam['type'])) ?> slam · <?= esc(ist($slam['opens_at'], 'D, d M Y')) ?> · <?= esc($slam['venue_name']) ?></div>
                <h2 class="page-title"><?= esc($slam['name']) ?></h2>
            </div>
            <?php if (empty($hideBadge)): ?><div class="col-auto"><?= status_badge('phase', $slam['status']) ?></div><?php endif ?>
        </div>
        <div class="mt-3 overflow-auto">
            <ul class="nav nav-pills flex-nowrap" style="white-space:nowrap">
                <?php foreach ($items as $key => [$label, $url, $ic]): ?>
                    <li class="nav-item"><a class="nav-link <?= ($active ?? '') === $key ? 'active' : '' ?>" href="<?= site_url($url) ?>"><?= icon($ic, 'me-1') ?><?= esc($label) ?></a></li>
                <?php endforeach ?>
            </ul>
        </div>
    </div>
</div>
