<?php $req = service('request'); ?>
<?php if ($req->getGet('nocookie') !== null): ?>
    <div class="alert alert-warning" role="alert"><div class="d-flex gap-2"><?= icon('status.warning') ?><div>
        <strong>This browser is not keeping our login.</strong> Please open the page in <strong>Chrome</strong> (Android) or <strong>Safari</strong> (iPhone), not inside the QR scanner app, and not in a private tab. Then scan your badge again, or type your ID. Still stuck? Visit the volunteer desk.
    </div></div></div>
<?php elseif ($req->getGet('retry') !== null): ?>
    <div class="alert alert-warning" role="alert"><div class="d-flex gap-2"><?= icon('status.warning') ?><div>That page had expired. Please try again.</div></div></div>
<?php endif ?>
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
