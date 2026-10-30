<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'reviewers']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="row justify-content-center"><div class="col-12 col-lg-8">
<form class="card card-accent accent-blue" method="post" action="<?= site_url('admin/attendees/import/preview') ?>" enctype="multipart/form-data" data-once>
    <?= csrf_field() ?>
    <input type="hidden" name="slam_id" value="<?= (int) $slam['id'] ?>">
    <div class="card-header"><h3 class="card-title">Import judges and experts (step 1 of 2: check the file)</h3></div>
    <div class="card-body">
        <p>Upload a CSV with a header row. Nothing is saved until you confirm on the next screen. Importing never checks anyone in.</p>
        <div class="table-responsive mb-3">
            <table class="table table-sm">
                <thead><tr><th>Column</th><th>Required</th><th>Rules</th></tr></thead>
                <tbody>
                    <tr><td><code>name</code></td><td>yes</td><td>2–120 characters; Malayalam is fine</td></tr>
                    <tr><td><code>mobile</code></td><td>yes</td><td>10 digits (+91, spaces, dashes and a leading 0 are removed)</td></tr>
                    <tr><td><code>email</code></td><td>no</td><td>valid email</td></tr>
                    <tr><td><code>type</code></td><td>no</td><td><code>user</code> (a judge from the audience; also when blank) or <code>expert</code> (older files may say <code>judge</code>, which also means expert)</td></tr>
                    <tr><td><code>badge_id</code></td><td>no</td><td>blank = generated; else 2 letters (no I, O, Q, S, Z) + 5 digits</td></tr>
                </tbody>
            </table>
        </div>
        <a href="<?= site_url('admin/attendees/import/template') ?>" class="btn btn-sm mb-3"><?= icon('action.download') ?>Download template CSV</a>
        <div class="mb-3">
            <label class="form-label required" for="file">CSV file (max 5,000 rows, 2 MB)</label>
            <input id="file" name="file" type="file" class="form-control" accept=".csv,text/csv" required>
            <div class="form-hint">UTF-8 (with or without BOM) or Excel "CSV UTF-8"; comma or semicolon separated.</div>
        </div>
        <div class="mb-0">
            <label class="form-label">If a mobile number is already registered for this slam</label>
            <label class="form-check"><input class="form-check-input" type="radio" name="mode" value="skip_existing" checked><span class="form-check-label">Skip it (default)</span></label>
            <label class="form-check"><input class="form-check-input" type="radio" name="mode" value="update_existing"><span class="form-check-label">Update name, email and type (badge IDs, check-ins and reviews never change)</span></label>
        </div>
    </div>
    <div class="card-footer text-end"><button class="btn btn-primary" type="submit"><?= icon('action.upload') ?>Check file</button></div>
</form>
</div></div>
<?= $this->endSection() ?>
