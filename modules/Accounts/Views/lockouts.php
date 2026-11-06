<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?>
<div class="page-header"><div class="container-xl"><div class="row g-2 align-items-center">
    <div class="col"><h2 class="page-title">Lockouts</h2>
        <div class="text-secondary">Everyone on one Wi-Fi shares one network address. Too many wrong badge IDs from it (<?= (int) $cfg->ipMaxFailures ?> in <?= (int) $cfg->ipWindowMinutes ?> minutes, or <?= (int) $cfg->ipMaxDistinctIds ?> different IDs in <?= (int) $cfg->ipDistinctWindowMinutes ?> minutes) lock the whole network out of check-in. Unlock it here.</div></div>
    <div class="col-auto"><a href="<?= site_url('admin/lockouts') ?>" class="btn"><?= icon('action.refresh') ?>Refresh</a></div>
</div></div></div>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr><th>Network</th><th>Status</th><th class="text-end">Wrong IDs</th><th class="text-end">Different IDs</th><th class="text-end">"Am I registered?" checks</th><th>Last attempt</th><th></th></tr></thead>
            <tbody>
            <?php if ($networks === []): ?>
                <tr><td colspan="7" class="text-center text-secondary py-4">No failed check-ins in the last hour.</td></tr>
            <?php endif ?>
            <?php foreach ($networks as $n): ?>
                <tr<?= $n['locked_seconds'] > 0 ? ' class="table-danger"' : '' ?>>
                    <td><code><?= esc($n['ip']) ?></code><?= $n['ip'] === $myIp ? ' <span class="badge bg-blue-lt">your network</span>' : '' ?></td>
                    <td><?= $n['locked_seconds'] > 0
                        ? '<span class="badge bg-red-lt">' . icon('status.lock', 'icon-inline me-1') . 'Locked, ' . (int) ceil($n['locked_seconds'] / 60) . ' min left</span>'
                        : '<span class="badge bg-green-lt">Not locked</span>' ?></td>
                    <td class="text-end"><?= (int) $n['failures'] ?></td>
                    <td class="text-end"><?= (int) $n['distinct_ids'] ?></td>
                    <td class="text-end"><?= (int) $n['lookups'] ?></td>
                    <td><?= esc(local_time($n['last_at'], 'd M H:i')) ?></td>
                    <td class="text-end">
                        <form method="post" action="<?= site_url('admin/lockouts/unlock') ?>" class="d-inline" data-confirm="Unlock <?= esc($n['ip'], 'attr') ?>? Its failed attempts are cleared, so everyone on it can try again." data-reason="reason" data-ok="Unlock">
                            <?= csrf_field() ?>
                            <input type="hidden" name="ip" value="<?= esc($n['ip'], 'attr') ?>">
                            <button class="btn btn-sm<?= $n['locked_seconds'] > 0 ? ' btn-danger' : '' ?>" type="submit"><?= icon('status.lock') ?>Unlock</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-secondary">One badge is still locked on its own after <?= (int) $cfg->idMaxFailures ?> wrong tries; unlocking a network does not change that. Each unlock is recorded in the activity log.</div>
</div>
<?= $this->endSection() ?>
