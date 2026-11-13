<?php $this->setVar('title', $slam['name'] . ' · presenters'); $this->setVar('indexable', true); ?>
<?= $this->extend('Modules\Core\Views\layouts\public') ?>
<?= $this->section('content') ?>
<header class="slam-head">
    <p class="slam-eyebrow">Kerala Science Slam – <?= esc(local_time($slam['opens_at'], 'j M')) ?> – <?= esc($slam['venue_name']) ?></p>
    <h1 class="slam-title ml-text"><?= esc($slam['name']) ?></h1>
    <div class="d-flex flex-wrap gap-2 mt-3">
        <?php if (empty($doc['finished'])): ?>
            <a class="btn btn-sm" href="<?= site_url('slams/' . $slam['slug']) ?>"><?= icon('nav.schedule') ?>Schedule</a>
        <?php endif ?>
        <?php if (! empty($doc['results_url'])): ?>
            <a class="btn btn-sm btn-primary" href="<?= esc($doc['results_url'], 'attr') ?>"><?= icon('nav.marks') ?>Results</a>
        <?php endif ?>
    </div>
</header>
<?php if (! empty($doc['finished'])): ?>
    <div class="alert alert-info my-3" role="status">
        <?php if (! empty($doc['results_url'])): ?>
            Thank you! The slam is closed. The results are out: <a href="<?= esc($doc['results_url'], 'attr') ?>">see the results</a>.
        <?php else: ?>
            Thank you! The slam is closed. The results will be announced soon.
        <?php endif ?>
    </div>
<?php endif ?>
<?php if ($talks === []): ?>
    <p class="text-secondary">The presenters will appear here once they are announced.</p>
<?php else: ?>
    <div class="slam-grid">
        <?php foreach ($talks as $t): ?>
            <article class="slam-grid-card" id="p-<?= (int) $t['id'] ?>">
                <div class="slam-grid-photo">
                    <?php if ($t['thumb']): ?>
                        <img src="<?= esc($t['thumb'], 'attr') ?>"<?php if ($t['photo']): ?> srcset="<?= esc($t['thumb'], 'attr') ?> 200w, <?= esc($t['photo'], 'attr') ?> 800w" sizes="(min-width: 992px) 25vw, (min-width: 576px) 33vw, 50vw"<?php endif ?> alt="<?= esc($t['presenter_name'], 'attr') ?>" loading="lazy">
                    <?php else: ?>
                        <?= icon('nav.presenters', 'icon-xl') ?>
                    <?php endif ?>
                </div>
                <div class="slam-grid-text">
                    <h2 class="slam-grid-name ml-text"><?= esc($t['presenter_name']) ?></h2>
                    <?php if ($t['designation']): ?><p class="slam-grid-inst ml-text"><?= esc($t['designation']) ?></p><?php endif ?>
                    <p class="slam-grid-talk ml-text"><?= esc($t['topic']) ?></p>
                    <?php if ($t['abstract'] || $t['profile']): ?>
                        <details class="slam-grid-more"><summary>About this talk</summary>
                            <?php if ($t['abstract']): ?><p class="ml-text"><?= nl2br_esc($t['abstract']) ?></p><?php endif ?>
                            <?php if ($t['profile']): ?><p class="small text-secondary ml-text"><?= nl2br_esc($t['profile']) ?></p><?php endif ?>
                        </details>
                    <?php endif ?>
                </div>
            </article>
        <?php endforeach ?>
    </div>
<?php endif ?>
<?= $this->endSection() ?>
