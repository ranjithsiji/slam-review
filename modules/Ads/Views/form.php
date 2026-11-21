<?php
/**
 * The ad form (a slam's own ad or a pool ad).
 * @var string $action @var string $cancel @var string $newTitle @var array $e @var int $count @var array $errors
 */
$kind = old('kind', $e['kind'] ?? 'text');
?>
        <form class="card card-accent accent-orange" method="post" action="<?= esc($action, 'attr') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <?php if ($e): ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><?php endif ?>
            <div class="card-header"><h3 class="card-title"><?= icon('action.add', 'me-2') ?><?= $e ? 'Edit ad' : esc($newTitle) ?></h3></div>
            <div class="card-body">
                <p class="small text-secondary">Ads are shown to the audience (judges' phones and the public page) <strong>only while a tea or lunch break is running</strong>, rotating through the active ones.</p>
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
                    <div class="col-6"><label class="form-label" for="sort_order">Order</label><input id="sort_order" name="sort_order" type="number" class="form-control" value="<?= (int) old('sort_order', $e['sort_order'] ?? $count + 1) ?>"></div>
                    <div class="col-6 d-flex align-items-end">
                        <label class="form-check form-switch mb-2"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?= (int) old('is_active', $e['is_active'] ?? 1) ? 'checked' : '' ?>><span class="form-check-label">Active</span></label>
                    </div>
                </div>
            </div>
            <div class="card-footer text-end">
                <?php if ($e): ?><a href="<?= esc($cancel, 'attr') ?>" class="btn btn-link">Cancel</a><?php endif ?>
                <button class="btn btn-primary" type="submit"><?= icon('action.save') ?>Save ad</button>
            </div>
        </form>
