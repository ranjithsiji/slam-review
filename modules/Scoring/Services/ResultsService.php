<?php

declare(strict_types=1);

namespace Modules\Scoring\Services;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/**
 * The result sheet: the top presenters of a slam (5 in a regional slam, 3 in the final), taken from the current
 * mark run. Positions are not shown on the sheet or on the home page; names appear alphabetically unless marks are asked for.
 */
class ResultsService
{
    public function topCount(array $slam): int
    {
        return $slam['type'] === 'final' ? 3 : 5;
    }

    /**
     * @return list<array<string, mixed>> presenter_name, designation, topic, thumb, final_mark (out of 10), rank
     */
    public function top(int $slamId, ?bool $alphabetical = null): array
    {
        $slam = service('slams')->find($slamId);
        $run  = service('markRuns')->currentRun($slamId);
        if ($run === null) {
            return [];
        }
        // Regional slams: names only, alphabetical, no positions. The final: clear positions, best first.
        $alphabetical ??= $slam['type'] !== 'final';
        $rows = Db::rows(
            'SELECT mr.final_mark, mr.`rank`, pr.name AS presenter_name, pr.designation_or_institution AS designation, p.topic, pr.photo_thumb_path
               FROM mark_results mr JOIN performances p ON p.id = mr.performance_id JOIN presenters pr ON pr.id = p.presenter_id
              WHERE mr.mark_run_id = ? AND mr.final_mark IS NOT NULL AND mr.`rank` IS NOT NULL AND mr.`rank` <= ?
              ORDER BY mr.`rank`, pr.name',
            [$run['id'], $this->topCount($slam)],
        );
        if ($alphabetical) {
            usort($rows, static fn ($a, $b) => strcasecmp($a['presenter_name'], $b['presenter_name']));
        }

        return array_map(static fn ($r) => [
            'presenter_name' => $r['presenter_name'], 'designation' => $r['designation'], 'topic' => $r['topic'],
            'thumb' => $r['photo_thumb_path'] !== null ? '/' . $r['photo_thumb_path'] : null, 'final_mark' => (float) $r['final_mark'], 'rank' => (int) $r['rank'],
        ], $rows);
    }

    /**
     * The complete ranking with each group's average, for the final's result sheet.
     *
     * @return list<array<string, mixed>>
     */
    public function ranking(int $slamId): array
    {
        $run = service('markRuns')->currentRun($slamId);
        if ($run === null) {
            return [];
        }

        return array_map(static fn ($r) => [
            'rank' => (int) $r['rank'], 'presenter_name' => $r['presenter_name'], 'designation' => $r['designation'], 'topic' => $r['topic'],
            'final_mark' => (float) $r['final_mark'], 'avg_general' => $r['avg_general'] !== null ? (float) $r['avg_general'] : null,
            'avg_representative' => $r['avg_representative'] !== null ? (float) $r['avg_representative'] : null, 'avg_judge' => $r['avg_judge'] !== null ? (float) $r['avg_judge'] : null,
        ], Db::rows(
            'SELECT mr.final_mark, mr.`rank`, mr.avg_general, mr.avg_representative, mr.avg_judge, pr.name AS presenter_name, pr.designation_or_institution AS designation, p.topic
               FROM mark_results mr JOIN performances p ON p.id = mr.performance_id JOIN presenters pr ON pr.id = p.presenter_id
              WHERE mr.mark_run_id = ? AND mr.final_mark IS NOT NULL AND mr.`rank` IS NOT NULL ORDER BY mr.`rank`, pr.name',
            [$run['id']],
        ));
    }

    public function setPublic(int $slamId, bool $public, Actor $actor): void
    {
        $slam = service('slams')->find($slamId);
        if ($public) {
            if (service('markRuns')->currentRun($slamId) === null || ! in_array($slam['status'], ['closed', 'marks_calculated', 'ended'], true)) {
                throw new DomainException('forbidden_transition', 'Results can be published after Slam Close, once the marks are calculated.', 409);
            }
        }
        db_connect()->table('slams')->where('id', $slamId)->update([
            'results_is_public' => $public ? 1 : 0, 'results_published_at' => $public ? Clock::db() : null, 'updated_at' => Clock::db(),
        ]);
        service('activityLog')->log($actor, $public ? 'results.published' : 'results.unpublished', ['slam_id' => $slamId, 'target_type' => 'slam', 'target_id' => $slamId]);
        service('publicCache')->purge($slamId);
    }

    /**
     * Published results for the home page, newest first.
     *
     * @return list<array{slam: array<string, mixed>, top: list<array<string, mixed>>}>
     */
    public function published(): array
    {
        $out = [];
        foreach (Db::rows("SELECT * FROM slams WHERE results_is_public = 1 AND status IN ('closed','marks_calculated','ended') ORDER BY opens_at DESC") as $s) {
            $top = $this->top((int) $s['id']);
            if ($top !== []) {
                $out[] = ['slam' => $s, 'top' => $top];
            }
        }

        return $out;
    }
}
