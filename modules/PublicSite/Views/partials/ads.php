<?php /** Break ads, rotating in the browser (frontend/entries/public.js). @var list<array> $ads */ ?>
<?php if ($ads !== []): ?>
<div class="card ad-card mt-3" role="complementary" aria-label="Sponsor message" data-ad-rotate>
    <div class="card-body">
        <?php foreach ($ads as $i => $a): ?>
            <div class="ad-slide" <?= $i === 0 ? '' : 'hidden' ?>>
                <div class="small text-secondary text-uppercase fw-bold mb-2"><?= esc($a['sponsor'] ?: 'Sponsored') ?></div>
                <?php $open = $a['link'] ? '<a href="' . esc($a['link'], 'attr') . '" target="_blank" rel="noopener noreferrer sponsored" class="text-reset text-decoration-none d-block">' : '<div>'; ?>
                <?= $open ?>
                    <?php if ($a['image']): ?><img src="<?= esc($a['image'], 'attr') ?>" alt="<?= esc($a['headline'] ?: ($a['sponsor'] ?: 'Advertisement'), 'attr') ?>" class="ad-image rounded w-100" loading="lazy"><?php endif ?>
                    <?php if ($a['headline']): ?><div class="h2 mt-2 mb-1 ml-text"><?= esc($a['headline']) ?></div><?php endif ?>
                    <?php if ($a['body']): ?><div class="ml-text" style="white-space:pre-line"><?= esc($a['body']) ?></div><?php endif ?>
                <?= $a['link'] ? '</a>' : '</div>' ?>
            </div>
        <?php endforeach ?>
    </div>
</div>
<?php endif ?>
