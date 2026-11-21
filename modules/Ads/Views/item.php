<?php
/**
 * One ad in a list. $editUrl null = read-only (a pool ad seen from a slam).
 * @var array $a @var ?string $editUrl @var string $deleteUrl (sprintf with the ad id) @var string $confirm
 */
?>
                    <div class="list-group-item">
                        <div class="d-flex gap-3 align-items-start">
                            <?php if ($a['image_thumb_path']): ?><img src="<?= base_url($a['image_thumb_path']) ?>" alt="" class="rounded flex-none" style="width:6rem;height:4rem;object-fit:cover"><?php else: ?><span class="avatar avatar-lg flex-none"><?= icon('about.talk') ?></span><?php endif ?>
                            <div class="flex-fill min-w-0">
                                <div class="d-flex flex-wrap gap-2 mb-1">
                                    <span class="badge <?= $a['kind'] === 'image' ? 'bg-purple-lt' : 'bg-blue-lt' ?>"><?= $a['kind'] === 'image' ? 'Image' : 'Text' ?></span>
                                    <span class="badge <?= (int) $a['is_active'] ? 'bg-green-lt' : 'bg-secondary-lt' ?>"><?= (int) $a['is_active'] ? 'Active' : 'Off' ?></span>
                                    <?php if ($a['sponsor']): ?><span class="small text-secondary ml-text"><?= esc($a['sponsor']) ?></span><?php endif ?>
                                </div>
                                <?php if ($a['headline']): ?><div class="fw-bold ml-text"><?= esc($a['headline']) ?></div><?php endif ?>
                                <?php if ($a['body']): ?><div class="small ml-text"><?= esc($a['body']) ?></div><?php endif ?>
                                <?php if (! empty($a['slams'])): ?><div class="small text-secondary">Shown at: <?= esc(implode(', ', array_column($a['slams'], 'name'))) ?></div><?php elseif (array_key_exists('slams', $a)): ?><div class="small text-secondary">Not chosen by any slam yet.</div><?php endif ?>
                                <?php if ($a['link_url']): ?><div class="small text-truncate"><a href="<?= esc($a['link_url']) ?>" target="_blank" rel="noopener noreferrer"><?= esc($a['link_url']) ?></a></div><?php endif ?>
                            </div>
                            <div class="d-flex flex-column gap-1">
                                <?php if ($editUrl !== null): ?>
                                <a class="btn btn-sm" href="<?= esc($editUrl . (int) $a['id'], 'attr') ?>"><?= icon('action.edit') ?>Edit</a>
                                <form method="post" action="<?= esc(sprintf($deleteUrl, (int) $a['id']), 'attr') ?>" data-confirm="<?= esc($confirm, 'attr') ?>" data-danger data-ok="Delete"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger w-100" type="submit"><?= icon('action.delete') ?>Delete</button></form>
                                <?php endif ?>
                            </div>
                        </div>
                    </div>
