<?php
/**
 * Breadcrumb trail for organiser pages.
 * @var list<array{0: string, 1: ?string}> $crumbs [label, url|null]; the last one is the current page.
 */
$crumbs = array_merge([['Dashboard', 'admin']], $crumbs ?? []);
$last   = count($crumbs) - 1;
?>
<nav aria-label="Breadcrumb" class="mb-1">
    <ol class="breadcrumb breadcrumb-arrows mb-0">
        <?php foreach ($crumbs as $i => [$label, $url]): ?>
            <?php if ($i === $last || $url === null): ?>
                <li class="breadcrumb-item active" aria-current="page"><span class="ml-text"><?= esc($label) ?></span></li>
            <?php else: ?>
                <li class="breadcrumb-item"><a href="<?= site_url($url) ?>"><?= $i === 0 ? icon('nav.home', 'icon-inline me-1') : '' ?><?= esc($label) ?></a></li>
            <?php endif ?>
        <?php endforeach ?>
    </ol>
</nav>
