<?php
/**
 * How each final mark is built from the three groups.
 * @var list<array> $rows  result rows with 'breakdown' (ScoringService::breakdown)
 * @var array $weights
 */
$n   = static fn (?float $v, int $dp = 2) => $v === null ? '—' : number_format($v, $dp);
$m   = static fn (?float $v) => $v === null ? '—' : number_format($v * 10, 2); // marks are stored out of 10 and shown out of 100
$cnt = ['general' => 'n_general', 'representative' => 'n_representative', 'judge' => 'n_judge'];
$questions ??= [];
// Per-question averages for a group, e.g. "Communication 2.4/3 · Engagement 2.1/3".
$detail = static function (array $r, string $g) use ($questions): string {
    $pq    = is_string($r['per_question'] ?? null) ? (json_decode($r['per_question'], true) ?: []) : ($r['per_question'] ?? []);
    $parts = [];
    foreach ($pq as $qid => $groups) {
        $q = $questions[$qid] ?? null;
        if ($q === null || ! isset($groups[$g]) || $groups[$g] === null) {
            continue;
        }
        $parts[] = esc($q['text']) . ' ' . number_format((float) $groups[$g], 1) . '/' . (int) $q['max_score'];
    }

    return implode(' · ', $parts);
};
?>
<div class="card mb-3 card-accent accent-orange">
    <div class="card-header"><h3 class="card-title"><?= icon('action.calculate', 'me-2') ?>How the final marks are calculated</h3></div>
    <div class="card-body border-bottom">
        <ol class="mb-2 ps-3">
            <li>Each review gets a mark out of 10 from its answers: <code>10 × Σ(weight × marks) ÷ Σ(weight × maximum)</code>. With weight 1 that is the marks given scaled to 10: the two audience questions of 5 add up to 10, and the judges' criteria (3 + 3 + 3 + 1) add up to 10.</li>
            <li>The averages and the final mark are shown <strong>out of 100</strong> (the review mark × 10), to two decimal places. For every performance the reviews are averaged in three groups: <strong>general reviewers</strong> (audience users who are not representatives), <strong>audience representatives</strong> and <strong>judges</strong>.</li>
            <li>Final mark = Σ (group average × group weight) ÷ Σ weights of the groups that have reviews. A group without reviews drops out and its weight is shared among the others in proportion.</li>
        </ol>
        <div class="d-flex flex-wrap gap-2 small">
            <span class="badge bg-blue-lt">General reviewers <?= $n((float) $weights['w_general'], 0) ?>%</span>
            <span class="badge bg-purple-lt">Representatives <?= $n((float) $weights['w_representative'], 0) ?>%</span>
            <span class="badge bg-orange-lt">Judges <?= $n((float) $weights['w_judge'], 0) ?>%</span>
        </div>
    </div>
    <div class="list-group list-group-flush">
        <?php foreach ($rows as $r): $b = $r['breakdown']; ?>
            <div class="list-group-item">
                <div class="d-flex align-items-start gap-2 mb-2">
                    <span class="badge bg-secondary-lt fs-4" title="Rank"><?= $r['rank'] !== null ? '#' . (int) $r['rank'] : '—' ?></span>
                    <div class="flex-fill min-w-0">
                        <div class="fw-bold ml-text"><?= esc($r['presenter_name']) ?></div>
                        <div class="small text-secondary ml-text text-truncate"><?= esc($r['topic']) ?></div>
                    </div>
                    <div class="text-end"><div class="h2 mb-0"><?= $m($b['final']) ?></div><div class="small text-secondary">out of 100</div></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Group</th><th class="text-end">Reviews</th><th class="text-end">Average /100</th><th class="text-end">Weight</th><th class="text-end">Share</th><th class="text-end">Points</th></tr></thead>
                        <tbody>
                        <?php foreach ($b['groups'] as $g => $x): $counts = $x['share'] !== null; ?>
                            <tr class="<?= $counts ? '' : 'text-secondary' ?>">
                                <td><?= esc($x['label']) ?><?php if (($d = $detail($r, $g)) !== ''): ?><div class="small text-secondary ml-text"><?= $d ?></div><?php endif ?></td>
                                <td class="text-end"><?= (int) ($r[$cnt[$g]] ?? 0) ?></td>
                                <td class="text-end"><?= $m($x['avg'] === null ? null : (float) $x['avg']) ?></td>
                                <td class="text-end"><?= $n($x['weight'], 0) ?>%</td>
                                <td class="text-end"><?= $counts ? $n($x['share'] * 100, 1) . '%' : '<span class="small">' . ($x['avg'] === null ? 'no reviews' : 'weight 0') . '</span>' ?></td>
                                <td class="text-end"><?= $counts ? $m($x['avg']) . ' × ' . $n($x['share'], 3) . ' = <strong>' . $m($x['contribution']) . '</strong>' : '—' ?></td>
                            </tr>
                        <?php endforeach ?>
                        <tr><td colspan="4" class="fw-bold">Final mark</td><td class="text-end"><?= $b['final'] !== null ? '100%' : '' ?></td><td class="text-end fw-bold"><?= $m($b['final']) ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach ?>
        <?php if ($rows === []): ?><div class="list-group-item text-secondary">No performances yet.</div><?php endif ?>
    </div>
</div>
