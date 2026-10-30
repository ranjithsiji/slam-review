<?php
/** Slam operator's switch between the three modes. @var string $mode  @var bool $vertical (sidebar) */
$tones = ['prepare' => ['primary', 'nav.schedule'], 'run' => ['danger', 'nav.status'], 'after' => ['success', 'nav.marks']];
?>
<form method="post" action="<?= site_url('operator/mode') ?>" class="d-grid <?= ! empty($vertical) ? '' : 'd-md-flex' ?> gap-1" role="group" aria-label="Operator mode">
    <?= csrf_field() ?>
    <?php foreach (\Modules\Core\Libraries\OperatorMode::LABELS as $key => $label): [$tone, $ic] = $tones[$key]; ?>
        <button class="btn <?= $mode === $key ? "btn-{$tone}" : "btn-outline-{$tone}" ?>" name="mode" value="<?= $key ?>" type="submit" <?= $mode === $key ? 'aria-pressed="true"' : '' ?>><?= icon($ic) ?><?= esc($label) ?></button>
    <?php endforeach ?>
</form>
