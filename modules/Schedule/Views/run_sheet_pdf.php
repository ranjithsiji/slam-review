<style>
    body { font-family: dejavusans; font-size: 10pt; }
    h1 { font-size: 16pt; margin: 0; }
    .meta { color: #555; margin-bottom: 6mm; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; border-bottom: 0.4mm solid #000; padding: 1.5mm; font-size: 9pt; }
    td { border-bottom: 0.2mm solid #bbb; padding: 2mm 1.5mm; vertical-align: top; }
    .brk td { background: #fff4e6; }
    .num { white-space: nowrap; }
</style>
<h1><?= esc($slam['name']) ?> — run-sheet</h1>
<div class="meta"><?= esc(local_time($slam['opens_at'], 'l, d F Y')) ?> · <?= esc($slam['venue_name']) ?> · times in <?= esc(tz_label()) ?> · printed <?= esc(local_time(\Modules\Core\Libraries\Clock::db(), 'd M H:i')) ?></div>
<table>
    <thead><tr><th>#</th><th>Start</th><th>End</th><th>Min</th><th>Presenter / break</th><th>Topic</th></tr></thead>
    <tbody>
    <?php $n = 0; foreach ($rows as $r): $isBreak = $r['item_type'] === 'break'; ?>
        <tr class="<?= $isBreak ? 'brk' : '' ?>">
            <td class="num"><?= $isBreak ? '' : ++$n ?></td>
            <td class="num"><?= esc(local_time($r['planned_start_at'], 'H:i')) ?><?= $r['is_pinned'] ? ' *' : '' ?></td>
            <td class="num"><?= esc(local_time(\Modules\Core\Libraries\Clock::addMinutes($r['planned_start_at'], (int) $r['duration_minutes']), 'H:i')) ?></td>
            <td class="num"><?= (int) $r['duration_minutes'] ?></td>
            <td><?= $isBreak ? '<b>' . esc($r['label']) . '</b>' : esc($r['presenter_name']) ?></td>
            <td><?= $isBreak ? '' : esc($r['topic']) ?></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
<p style="font-size:8pt;color:#555">* pinned time</p>
