<?php /** The talk on stage, for the public page. @var array $talk */ ?>
<div class="talk-now d-flex gap-3 align-items-start">
    <?php if (! empty($talk['thumb'])): ?><img class="talk-photo" src="<?= esc($talk['thumb']) ?>" alt="<?= esc($talk['photo_alt'] ?? $talk['presenter'], 'attr') ?>" width="96" height="96" loading="lazy"><?php else: ?><span class="avatar avatar-xl flex-none"><?= icon('person.user', 'icon-lg') ?></span><?php endif ?>
    <div class="min-w-0 flex-fill">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
            <span class="badge bg-red-lt"><?= icon('status.live', 'icon-inline me-1') ?><?= ! empty($talk['on_stage']) ? 'On stage now' : 'Talk just finished' ?></span>
            <?php if (! empty($talk['started_at'])): ?><span class="small text-secondary">Started <?= esc(local_time(str_replace(['T', 'Z'], [' ', ''], (string) $talk['started_at']), 'H:i')) ?></span><?php endif ?>
        </div>
        <div class="h2 mb-0 ml-text"><?= esc($talk['title']) ?></div>
        <div class="ml-text"><strong><?= esc($talk['presenter']) ?></strong><?= ! empty($talk['designation']) ? '<span class="text-secondary"> · ' . esc($talk['designation']) . '</span>' : '' ?></div>
        <?php if (! empty($talk['summary'])): ?><p class="mb-0 mt-1 text-secondary ml-text"><?= esc($talk['summary']) ?></p><?php endif ?>
    </div>
</div>
