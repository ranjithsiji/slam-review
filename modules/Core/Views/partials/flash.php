<?php foreach (['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'] as $key => $cls): ?>
    <?php if ($msg = session($key)): ?>
        <div class="alert alert-<?= $cls ?> alert-dismissible" role="<?= $cls === 'danger' ? 'alert' : 'status' ?>">
            <div class="d-flex gap-2">
                <?= icon($cls === 'success' ? 'status.ok' : ($cls === 'danger' ? 'status.error' : 'status.info')) ?>
                <div><?= esc($msg) ?></div>
            </div>
            <a class="btn-close" data-bs-dismiss="alert" aria-label="Close"></a>
        </div>
    <?php endif ?>
<?php endforeach ?>
