<?php $this->setVar('entry', 'frontend/entries/questions.js'); ?>
<?= $this->extend('Modules\Core\Views\layouts\admin') ?>
<?= $this->section('header') ?>
<?php if ($slam): ?><?= view('Modules\Core\Views\partials\slam_nav', ['slam' => $slam, 'active' => 'questions']) ?>
<?php else: ?><div class="page-header"><div class="container-xl"><h2 class="page-title">Default question templates</h2>
<div class="text-secondary">New slams copy these. Changing a template never changes an existing slam.</div></div></div><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div id="questions-app"><div class="text-center text-secondary py-5"><div class="spinner-border" role="status"></div></div></div>
<?= $this->endSection() ?>
<?= $this->section('boot') ?><?= boot_json([
    'slam_id' => $slam ? (int) $slam['id'] : null, 'locked' => $locked,
    'questions' => array_map(static fn ($list) => array_map(static fn ($q) => [
        'id' => (int) $q['id'], 'text' => $q['text'], 'text_ml' => $q['text_ml'], 'help_text' => $q['help_text'],
        'weight' => (float) $q['weight'], 'max_score' => (int) $q['max_score'], 'is_active' => (bool) $q['is_active'],
    ], $list), $questions),
    'other_slams' => array_map(static fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name']], $otherSlams),
]) ?><?= $this->endSection() ?>
