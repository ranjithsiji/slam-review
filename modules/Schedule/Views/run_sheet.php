<?= $this->extend('Modules\Core\Views\layouts\\' . $layout) ?>
<?= $this->section('content') ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <div class="me-auto">
        <h2 class="mb-0">Run-sheet</h2>
        <div class="text-secondary"><?= esc($slam['name']) ?> · <?= esc(ist($slam['opens_at'], 'D, d M Y')) ?> · <?= status_badge('phase', $slam['status']) ?></div>
    </div>
    <button type="button" class="btn no-print" data-print><?= icon('action.print') ?>Print</button>
</div>
<div class="card">
    <div class="list-group list-group-flush">
        <?php foreach ($rows as $i => $r): $isBreak = $r['item_type'] === 'break'; ?>
            <div class="list-group-item slot-card slot-<?= $isBreak ? 'break' : esc($r['status']) ?>">
                <div class="d-flex gap-3 align-items-center">
                    <div class="text-center" style="min-width:4.5rem">
                        <div class="h3 m-0"><?= esc(ist($r['planned_start_at'], 'H:i')) ?></div>
                        <div class="small text-secondary"><?= (int) $r['duration_minutes'] ?> min</div>
                    </div>
                    <?php if ($isBreak): ?>
                        <?= icon('break.' . $r['break_type'], 'icon-lg text-orange') ?>
                        <div class="flex-fill"><div class="fw-bold ml-text"><?= esc($r['label']) ?></div></div>
                    <?php else: ?>
                        <?php if ($r['photo_thumb_path']): ?><img class="avatar avatar-photo" src="<?= base_url($r['photo_thumb_path']) ?>" alt="" loading="lazy"><?php else: ?><span class="avatar"><?= icon('person.user') ?></span><?php endif ?>
                        <div class="flex-fill"><div class="fw-bold ml-text"><?= esc($r['presenter_name']) ?></div><div class="text-secondary ml-text"><?= esc($r['topic']) ?></div></div>
                    <?php endif ?>
                    <?php if ($r['is_pinned']): ?><?= icon('action.pin', 'text-secondary', 'Pinned time') ?><?php endif ?>
                    <?= status_badge('slot', $r['status']) ?>
                </div>
            </div>
        <?php endforeach ?>
        <?php if ($rows === []): ?><div class="list-group-item text-secondary">No schedule yet.</div><?php endif ?>
    </div>
</div>
<?= $this->endSection() ?>
