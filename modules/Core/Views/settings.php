<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('content') ?>
<?php $tz = old('timezone', $settings['timezone']); $tf = old('time_format', $settings['time_format']); ?>
<div class="row row-cards">
    <div class="col-12 col-lg-7">
        <form method="post" action="<?= site_url('admin/settings') ?>" class="card card-accent accent-blue">
            <?= csrf_field() ?>
            <div class="card-header"><h3 class="card-title"><?= icon('nav.system', 'me-2') ?>Time and timezone</h3></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="timezone">Timezone</label>
                    <select id="timezone" name="timezone" class="form-select<?= is_invalid($errors, 'timezone') ?>" data-tz-select>
                        <?php foreach ($zones as $region => $list): ?>
                            <optgroup label="<?= esc($region) ?>">
                                <?php foreach ($list as $z): ?><option value="<?= esc($z) ?>" <?= $z === $tz ? 'selected' : '' ?>><?= esc($z) ?></option><?php endforeach ?>
                            </optgroup>
                        <?php endforeach ?>
                    </select>
                    <?= field_error($errors, 'timezone') ?>
                    <div class="form-hint">Every time in the system is typed and shown in this timezone: slam times, the schedule, countdown labels, badges, run-sheets, exports and the public page. India uses <strong>Asia/Kolkata</strong>.</div>
                </div>
                <div class="mb-3">
                    <div class="form-label">Clock</div>
                    <div class="form-selectgroup">
                        <label class="form-selectgroup-item"><input type="radio" name="time_format" value="24h" class="form-selectgroup-input" <?= $tf === '24h' ? 'checked' : '' ?>><span class="form-selectgroup-label">24-hour (13:05)</span></label>
                        <label class="form-selectgroup-item"><input type="radio" name="time_format" value="12h" class="form-selectgroup-input" <?= $tf === '12h' ? 'checked' : '' ?>><span class="form-selectgroup-label">12-hour (1:05 PM)</span></label>
                    </div>
                    <?= field_error($errors, 'time_format') ?>
                </div>
                <div class="alert alert-info mb-0">
                    <?= icon('status.info', 'me-1') ?>Times are stored in UTC, so changing the timezone never moves an event: a slam at 09:00 IST shows as 07:30 if you switch to Asia/Dubai, because that is the same moment there. Change it before entering slam times, not during an event.
                </div>
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary" type="submit"><?= icon('action.save') ?>Save</button></div>
        </form>
    </div>
    <div class="col-12 col-lg-5">
        <div class="card card-accent accent-teal" data-server-clock="<?= esc($serverNow, 'attr') ?>">
            <div class="card-header"><h3 class="card-title"><?= icon('status.time', 'me-2') ?>Clock</h3></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-6">Server time (<?= esc(tz_label()) ?>)</dt><dd class="col-6 font-monospace" data-clock="local"><?= esc(local_time(\Modules\Core\Libraries\Clock::db(), 'H:i:s')) ?></dd>
                    <dt class="col-6">Server time (UTC)</dt><dd class="col-6 font-monospace" data-clock="utc"><?= esc(substr($serverNow, 11, 8)) ?></dd>
                    <dt class="col-6">In the selected timezone</dt><dd class="col-6 font-monospace" data-clock="preview">—</dd>
                    <dt class="col-6">This device's clock</dt><dd class="col-6" data-clock="drift">—</dd>
                </dl>
                <p class="small text-secondary mt-3 mb-0">Every deadline uses the server clock; phones only use their own clock to run countdowns, corrected by the difference to the server. If the server time above is wrong, fix the server's NTP sync (it is not a setting here). PHP default timezone: <?= esc($phpZone) ?>, database values: UTC.</p>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
