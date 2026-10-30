<?php
/** Slam sub-navigation. @var array $slam  @var string $active */
$id    = (int) $slam['id'];
$items = [
    'overview'   => ['Overview', "admin/slams/{$id}", 'nav.slams'],
    'status'     => ['Status', "admin/slams/{$id}/status", 'nav.status'],
    'schedule'   => ['Schedule', "admin/slams/{$id}/schedule", 'nav.schedule'],
    'presenters' => ['Presenters', "admin/slams/{$id}/presenters", 'nav.presenters'],
    'attendees'  => ['Attendees', "admin/slams/{$id}/attendees", 'nav.attendees'],
    'reviewers'  => ['Reviewers', "admin/slams/{$id}/reviewers", 'person.user'],
    'judgelist'  => ['Judges', "admin/slams/{$id}/judges", 'person.judge'],
    'checkins'   => ['Check-ins', "admin/slams/{$id}/checkins", 'nav.checkins'],
    'questions'  => ['Questions', "admin/slams/{$id}/questions", 'nav.questions'],
    'representatives' => ['Representatives', "admin/slams/{$id}/representatives", 'nav.representatives'],
];
// Entering judges' marks after the slam is a tab only for slams judged that way.
if (service('scoring')->judging($id)['timing'] === 'end_of_slam') {
    $items['judges'] = ["Judges' marks", "admin/slams/{$id}/judge-marks", 'person.judge'];
}
$items['marks'] = ['Marks', "admin/slams/{$id}/marks", 'nav.marks'];
$items['stats'] = ['Statistics', "admin/slams/{$id}/stats", 'nav.stats'];
?>
<?php
$tabLabel = ($active ?? 'overview') === 'overview' ? null : ($items[$active ?? '']['0'] ?? null);
$crumbs   = [['Slams', 'admin/slams'], [$slam['name'], $tabLabel ? "admin/slams/{$id}" : null]];
if ($tabLabel) {
    $crumbs[] = [$tabLabel, null];
}
?>
<div class="page-header d-print-none">
    <div class="container-fluid">
        <?= view('Modules\Core\Views\partials\breadcrumb', ['crumbs' => $crumbs]) ?>
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle"><?= esc(ucfirst($slam['type'])) ?> slam · <?= esc(local_time($slam['opens_at'], 'D, d M Y')) ?> · <?= esc($slam['venue_name']) ?></div>
                <h2 class="page-title"><?= esc($slam['name']) ?></h2>
            </div>
            <?php $others = array_filter(service('slams')->all(), static fn ($o) => (int) $o['id'] !== $id); ?>
            <?php if ($others !== []): ?>
                <div class="col-12 col-sm-auto">
                    <label class="visually-hidden" for="slam-switch">Switch slam</label>
                    <select id="slam-switch" class="form-select form-select-sm" data-nav-select aria-label="Switch slam">
                        <option value=""><?= esc($slam['name']) ?> (this slam)</option>
                        <?php foreach ($others as $o): ?><option value="<?= site_url(str_replace("/slams/{$id}", '/slams/' . (int) $o['id'], $items[$active ?? 'overview'][1])) ?>"><?= esc($o['name']) ?></option><?php endforeach ?>
                    </select>
                </div>
            <?php endif ?>
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
