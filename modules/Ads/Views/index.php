<?php /** @var array $slam  @var list<array> $ads  @var ?array $editing */ ?>
<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'ads']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $sid = (int) $slam['id']; $e = $editing ?? []; $kind = old('kind', $e['kind'] ?? 'text'); ?>
<div class="row row-cards">
    <div class="col-12 col-lg-5">
        <form class="card card-accent accent-orange" method="post" action="<?= site_url("admin/slams/{$sid}/ads") ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <?php if ($e): ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><?php endif ?>
            <div class="card-header"><h3 class="card-title"><?= icon('action.add', 'me-2') ?><?= $e ? 'Edit ad' : 'New ad' ?></h3></div>
            <div class="card-body">
                <p class="small text-secondary">Ads are shown to the audience (reviewers' phones and the public page) <strong>only while a tea or lunch break is running</strong>, rotating through the active ones.</p>
                <div class="mb-3">
                    <label class="form-label">Type</label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="kind" id="k-text" value="text" <?= $kind === 'text' ? 'checked' : '' ?>><label class="btn" for="k-text"><?= icon('about.talk') ?>Text ad</label>
                        <input type="radio" class="btn-check" name="kind" id="k-image" value="image" <?= $kind === 'image' ? 'checked' : '' ?>><label class="btn" for="k-image"><?= icon('media.camera') ?>Image ad</label>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="sponsor">Sponsor (optional)</label>
                    <input id="sponsor" name="sponsor" class="form-control<?= is_invalid($errors, 'sponsor') ?>" maxlength="120" value="<?= esc(old('sponsor', $e['sponsor'] ?? '')) ?>">
                    <?= field_error($errors, 'sponsor') ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="headline">Headline</label>
                    <input id="headline" name="headline" class="form-control<?= is_invalid($errors, 'headline') ?>" maxlength="160" value="<?= esc(old('headline', $e['headline'] ?? '')) ?>">
                    <?= field_error($errors, 'headline') ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="body">Text</label>
                    <textarea id="body" name="body" class="form-control<?= is_invalid($errors, 'body') ?>" rows="3" maxlength="600"><?= esc(old('body', $e['body'] ?? '')) ?></textarea>
                    <?= field_error($errors, 'body') ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="image">Image <span class="text-secondary">(for image ads; JPG, PNG or WebP, up to 3 MB)</span></label>
                    <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" class="form-control<?= is_invalid($errors, 'image') ?>">
                    <?= field_error($errors, 'image') ?>
                    <?php if (! empty($e['image_thumb_path'])): ?><img src="<?= base_url($e['image_thumb_path']) ?>" alt="" class="mt-2 rounded" style="max-height:5rem"><div class="form-hint">Leave empty to keep this image.</div><?php endif ?>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="link_url">Link (optional)</label>
                    <input id="link_url" name="link_url" type="url" class="form-control<?= is_invalid($errors, 'link_url') ?>" placeholder="https://…" value="<?= esc(old('link_url', $e['link_url'] ?? '')) ?>">
                    <?= field_error($errors, 'link_url') ?>
                </div>
                <div class="row g-3">
                    <div class="col-6"><label class="form-label" for="sort_order">Order</label><input id="sort_order" name="sort_order" type="number" class="form-control" value="<?= (int) old('sort_order', $e['sort_order'] ?? count($ads) + 1) ?>"></div>
                    <div class="col-6 d-flex align-items-end">
                        <label class="form-check form-switch mb-2"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?= (int) old('is_active', $e['is_active'] ?? 1) ? 'checked' : '' ?>><span class="form-check-label">Active</span></label>
                    </div>
                </div>
            </div>
            <div class="card-footer text-end">
                <?php if ($e): ?><a href="<?= site_url("admin/slams/{$sid}/ads") ?>" class="btn btn-link">Cancel</a><?php endif ?>
                <button class="btn btn-primary" type="submit"><?= icon('action.save') ?>Save ad</button>
            </div>
        </form>
    </div>
    <div class="col-12 col-lg-7">
        <div class="card card-accent accent-blue">
            <div class="card-header"><h3 class="card-title">Ads for this slam (<?= count($ads) ?>)</h3></div>
            <?php if ($ads === []): ?>
                <div class="card-body text-secondary">No ads yet. Add a text or an image ad on the left.</div>
            <?php endif ?>
            <div class="list-group list-group-flush">
                <?php foreach ($ads as $a): ?>
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
                                <?php if ($a['link_url']): ?><div class="small text-truncate"><a href="<?= esc($a['link_url']) ?>" target="_blank" rel="noopener noreferrer"><?= esc($a['link_url']) ?></a></div><?php endif ?>
                            </div>
                            <div class="d-flex flex-column gap-1">
                                <a class="btn btn-sm" href="<?= site_url("admin/slams/{$sid}/ads?edit=" . (int) $a['id']) ?>"><?= icon('action.edit') ?>Edit</a>
                                <form method="post" action="<?= site_url("admin/slams/{$sid}/ads/" . (int) $a['id'] . '/delete') ?>" data-confirm="Delete this ad?" data-danger data-ok="Delete"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger w-100" type="submit"><?= icon('action.delete') ?>Delete</button></form>
                            </div>
                        </div>
                    </div>
                <?php endforeach ?>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
