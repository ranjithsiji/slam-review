<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?php
$v   = static fn (string $f, $default = '') => old($f, $slam[$f] ?? $default);
$dt  = static fn (string $f) => old($f, isset($slam[$f]) ? ist_input($slam[$f]) : '');
$num = static fn (string $f, int $d) => (int) old($f, $slam[$f] ?? $d);
?>
<?= $this->section('content') ?>
<form method="post" action="<?= site_url($slam ? 'admin/slams/' . $slam['id'] : 'admin/slams/create') ?>">
    <?= csrf_field() ?>
    <div class="row row-cards">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title"><?= esc($title) ?></h3></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label required" for="name">Name</label>
                        <input id="name" name="name" class="form-control<?= is_invalid($errors, 'name') ?>" required maxlength="150" value="<?= esc($v('name')) ?>">
                        <?= field_error($errors, 'name') ?>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label" for="type">Type</label>
                            <select id="type" name="type" class="form-select">
                                <?php foreach (['regional' => 'Regional', 'final' => 'Final'] as $k => $l): ?><option value="<?= $k ?>" <?= $v('type', 'regional') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label" for="slug">Public link slug</label>
                            <input id="slug" name="slug" class="form-control<?= is_invalid($errors, 'slug') ?>" maxlength="80" placeholder="auto from name + date" value="<?= esc($v('slug')) ?>">
                            <?= field_error($errors, 'slug') ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="description">Short description</label>
                        <textarea id="description" name="description" class="form-control" rows="2"><?= esc($v('description')) ?></textarea>
                    </div>
                    <h4 class="mt-4">Venue</h4>
                    <div class="mb-3"><label class="form-label required" for="venue_name">Venue name</label><input id="venue_name" name="venue_name" class="form-control" required value="<?= esc($v('venue_name')) ?>"></div>
                    <div class="mb-3"><label class="form-label" for="address_line">Address</label><input id="address_line" name="address_line" class="form-control" value="<?= esc($v('address_line')) ?>"></div>
                    <div class="row">
                        <div class="col-6 mb-3"><label class="form-label" for="city">City</label><input id="city" name="city" class="form-control" value="<?= esc($v('city')) ?>"></div>
                        <div class="col-6 mb-3"><label class="form-label" for="district">District</label><input id="district" name="district" class="form-control" value="<?= esc($v('district')) ?>"></div>
                        <div class="col-6 mb-3"><label class="form-label" for="state">State</label><input id="state" name="state" class="form-control" value="<?= esc($v('state', 'Kerala')) ?>"></div>
                        <div class="col-6 mb-3"><label class="form-label" for="pincode">Pincode</label><input id="pincode" name="pincode" class="form-control<?= is_invalid($errors, 'pincode') ?>" inputmode="numeric" maxlength="6" value="<?= esc($v('pincode')) ?>"><?= field_error($errors, 'pincode') ?></div>
                        <div class="col-6 mb-3"><label class="form-label" for="latitude">Latitude</label><input id="latitude" name="latitude" class="form-control<?= is_invalid($errors, 'latitude') ?>" inputmode="decimal" value="<?= esc($v('latitude')) ?>"></div>
                        <div class="col-6 mb-3"><label class="form-label" for="longitude">Longitude</label><input id="longitude" name="longitude" class="form-control<?= is_invalid($errors, 'longitude') ?>" inputmode="decimal" value="<?= esc($v('longitude')) ?>"></div>
                    </div>
                    <div class="mb-3"><label class="form-label" for="map_url">Map link</label><input id="map_url" name="map_url" type="url" class="form-control<?= is_invalid($errors, 'map_url') ?>" placeholder="https://maps.google.com/…" value="<?= esc($v('map_url')) ?>"><?= field_error($errors, 'map_url') ?></div>
                    <h4 class="mt-4">Organiser contact <span class="text-secondary small fw-normal">(shown to reviewers, not public)</span></h4>
                    <div class="row">
                        <div class="col-12 col-md-4 mb-3"><label class="form-label" for="contact_name">Name</label><input id="contact_name" name="contact_name" class="form-control" value="<?= esc($v('contact_name')) ?>"></div>
                        <div class="col-6 col-md-4 mb-3"><label class="form-label" for="contact_phone">Phone</label><input id="contact_phone" name="contact_phone" type="tel" class="form-control" value="<?= esc($v('contact_phone')) ?>"></div>
                        <div class="col-6 col-md-4 mb-3"><label class="form-label" for="contact_email">Email</label><input id="contact_email" name="contact_email" type="email" class="form-control<?= is_invalid($errors, 'contact_email') ?>" value="<?= esc($v('contact_email')) ?>"><?= field_error($errors, 'contact_email') ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Slam times (IST)</h3></div>
                <div class="card-body">
                    <p class="text-secondary small">Order: slam open ≤ gates open &lt; gate close ≤ reviews start &lt; slam close. Leave the gate times blank to open and close check-in by hand. A slam may cross midnight.</p>
                    <?php foreach ([
                        ['opens_at', 'Slam open', true, 'Admin and volunteer panels go live (if the readiness check passes).'],
                        ['checkin_opens_at', 'Gates open (check-in)', false, ''],
                        ['checkin_closes_at', 'Gate close (check-in stops)', false, ''],
                        ['reviews_start_at', 'Reviews start (first performance)', false, 'Prefills the first slot in the schedule.'],
                        ['closes_at', 'Slam close — hard stop', true, 'Anything still open ends at this moment.'],
                        ['event_ends_at', 'Event ends automatically (optional)', false, 'Ending is irreversible; leave blank to end by hand.'],
                    ] as [$f, $label, $req, $hint]): ?>
                        <div class="mb-3">
                            <label class="form-label<?= $req ? ' required' : '' ?>" for="<?= $f ?>"><?= esc($label) ?></label>
                            <input id="<?= $f ?>" name="<?= $f ?>" type="datetime-local" class="form-control<?= is_invalid($errors, $f) ?>" value="<?= esc($dt($f)) ?>" <?= $req ? 'required' : '' ?>>
                            <?php if ($hint): ?><div class="form-hint"><?= esc($hint) ?></div><?php endif ?>
                            <?= field_error($errors, $f) ?>
                        </div>
                    <?php endforeach ?>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h3 class="card-title">Automation (minutes)</h3></div>
                <div class="card-body">
                    <p class="text-secondary small">Placeholders: confirm with the organisers before the event.</p>
                    <div class="row">
                        <?php foreach ([
                            ['default_review_minutes', 'Review window', 5], ['changeover_minutes', 'Changeover', 2], ['edit_window_minutes', 'Edit window (0 = skip)', 10],
                            ['close_grace_minutes', 'Edit close → slam close', 5], ['default_tea_break_minutes', 'Tea break', 15], ['default_lunch_break_minutes', 'Lunch break', 45],
                        ] as [$f, $label, $d]): ?>
                            <div class="col-6 mb-3">
                                <label class="form-label" for="<?= $f ?>"><?= esc($label) ?></label>
                                <input id="<?= $f ?>" name="<?= $f ?>" type="number" min="0" max="600" class="form-control<?= is_invalid($errors, $f) ?>" value="<?= $num($f, $d) ?>">
                                <?= field_error($errors, $f) ?>
                            </div>
                        <?php endforeach ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="sticky-actions d-flex justify-content-end gap-2 mt-3">
        <a href="<?= site_url($slam ? 'admin/slams/' . $slam['id'] : 'admin/slams') ?>" class="btn btn-link">Cancel</a>
        <button type="submit" class="btn btn-primary"><?= icon('action.save') ?>Save slam</button>
    </div>
</form>
<?= $this->endSection() ?>
