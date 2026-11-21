<?php /** @var array $slam  @var list<array> $ads  @var list<array> $pool  @var list<int> $chosen  @var ?array $editing  @var bool $canManagePool */ ?>
<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'ads']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php $sid = (int) $slam['id']; ?>
<p class="text-secondary">Ads are shown to the audience (judges' phones, the public page and the TV) <strong>only while a tea or lunch break is running</strong>, rotating through the active ones: this slam's own ads first, then the ones chosen from the ad pool.</p>
<div class="row row-cards">
    <div class="col-12 col-lg-5">
        <?= view('Modules\Ads\Views\form', ['action' => site_url("admin/slams/{$sid}/ads"), 'cancel' => site_url("admin/slams/{$sid}/ads"), 'newTitle' => 'New ad for this slam', 'e' => $editing ?? [], 'count' => count($ads), 'errors' => $errors ?? []]) ?>
    </div>
    <div class="col-12 col-lg-7">
        <div class="card card-accent accent-purple mb-3">
            <div class="card-header"><h3 class="card-title"><?= icon('media.photo', 'me-2') ?>From the ad pool (<?= count($chosen) ?> chosen)</h3>
                <?php if ($canManagePool): ?><div class="card-actions"><a class="btn btn-sm" href="<?= site_url('admin/ads') ?>"><?= icon('action.edit') ?>Manage the ad pool</a></div><?php endif ?>
            </div>
            <?php if ($pool === []): ?>
                <div class="card-body text-secondary">The ad pool is empty.<?php if ($canManagePool): ?> Add ads under <a href="<?= site_url('admin/ads') ?>">Ad pool</a> to use them at any slam.<?php endif ?></div>
            <?php else: ?>
                <form method="post" action="<?= site_url("admin/slams/{$sid}/ads/pool") ?>">
                    <?= csrf_field() ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($pool as $a): $on = in_array((int) $a['id'], $chosen, true); ?>
                            <label class="list-group-item d-flex gap-3 align-items-center" style="cursor:pointer">
                                <input class="form-check-input flex-none m-0" type="checkbox" name="pool[]" value="<?= (int) $a['id'] ?>" <?= $on ? 'checked' : '' ?> style="width:1.4rem;height:1.4rem">
                                <?php if ($a['image_thumb_path']): ?><img src="<?= base_url($a['image_thumb_path']) ?>" alt="" class="rounded flex-none" style="width:5rem;height:3.4rem;object-fit:cover"><?php else: ?><span class="avatar flex-none"><?= icon('about.talk') ?></span><?php endif ?>
                                <span class="flex-fill min-w-0">
                                    <span class="d-flex flex-wrap gap-2 mb-1">
                                        <span class="badge <?= $a['kind'] === 'image' ? 'bg-purple-lt' : 'bg-blue-lt' ?>"><?= $a['kind'] === 'image' ? 'Image' : 'Text' ?></span>
                                        <?php if (! (int) $a['is_active']): ?><span class="badge bg-secondary-lt">Off in the pool</span><?php endif ?>
                                        <?php if ($a['sponsor']): ?><span class="small text-secondary ml-text"><?= esc($a['sponsor']) ?></span><?php endif ?>
                                    </span>
                                    <?php if ($a['headline']): ?><span class="d-block fw-bold ml-text text-truncate"><?= esc($a['headline']) ?></span><?php endif ?>
                                    <?php if ($a['body']): ?><span class="d-block small ml-text text-truncate"><?= esc($a['body']) ?></span><?php endif ?>
                                </span>
                            </label>
                        <?php endforeach ?>
                    </div>
                    <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
                        <span class="small text-secondary me-auto">Ticked ads are shown at this slam's breaks. A pool ad switched off in the pool is not shown anywhere.</span>
                        <button class="btn btn-primary" type="submit"><?= icon('action.save') ?>Save choice</button>
                    </div>
                </form>
            <?php endif ?>
        </div>
        <div class="card card-accent accent-blue">
            <div class="card-header"><h3 class="card-title">This slam's own ads (<?= count($ads) ?>)</h3></div>
            <?php if ($ads === []): ?>
                <div class="card-body text-secondary">No ads of its own. Add a text or an image ad on the left, or choose from the ad pool above.</div>
            <?php endif ?>
            <div class="list-group list-group-flush">
                <?php foreach ($ads as $a): ?>
                    <?= view('Modules\Ads\Views\item', ['a' => $a, 'editUrl' => site_url("admin/slams/{$sid}/ads?edit="), 'deleteUrl' => site_url("admin/slams/{$sid}/ads/%d/delete"), 'confirm' => 'Delete this ad?']) ?>
                <?php endforeach ?>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
