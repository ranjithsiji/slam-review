<?php /** @var list<array> $ads  @var ?array $editing */ ?>
<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('content') ?>
<p class="text-secondary">Ads in the pool belong to no slam. Each slam chooses the ones it shows, on its <strong>Break ads</strong> screen. Changing a pool ad here changes it at every slam that shows it; switching it off hides it everywhere.</p>
<div class="row row-cards">
    <div class="col-12 col-lg-5">
        <?= view('Modules\Ads\Views\form', ['action' => site_url('admin/ads'), 'cancel' => site_url('admin/ads'), 'newTitle' => 'New pool ad', 'e' => $editing ?? [], 'count' => count($ads), 'errors' => $errors ?? []]) ?>
    </div>
    <div class="col-12 col-lg-7">
        <div class="card card-accent accent-purple">
            <div class="card-header"><h3 class="card-title">Ad pool (<?= count($ads) ?>)</h3></div>
            <?php if ($ads === []): ?>
                <div class="card-body text-secondary">No pool ads yet. Add a text or an image ad on the left.</div>
            <?php endif ?>
            <div class="list-group list-group-flush">
                <?php foreach ($ads as $a): ?>
                    <?= view('Modules\Ads\Views\item', ['a' => $a, 'editUrl' => site_url('admin/ads?edit='), 'deleteUrl' => site_url('admin/ads/%d/delete'), 'confirm' => 'Delete this pool ad? It also stops showing at every slam that chose it.']) ?>
                <?php endforeach ?>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
