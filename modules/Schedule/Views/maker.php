<?php $this->setVar('entry', 'frontend/entries/schedule.js'); ?>
<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'schedule']) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>
<div id="schedule-app"><div class="text-center text-secondary py-5"><div class="spinner-border" role="status"></div><div>Loading the schedule maker…</div></div></div>
<?= $this->endSection() ?>
<?= $this->section('boot') ?><?= boot_json([
    'slam_id' => (int) $slam['id'],
    'other_slams' => array_map(static fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name']], $otherSlams),
    'public_url' => site_url('slams/' . $slam['slug']), 'is_public' => (bool) $slam['schedule_is_public'],
]) ?><?= $this->endSection() ?>
