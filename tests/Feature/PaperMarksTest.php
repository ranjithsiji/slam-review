<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Tests\Support\SlamTestCase;

final class PaperMarksTest extends SlamTestCase
{
    private function closedSlam(): array
    {
        $s = $this->makeSlam(2, 4, 2, ['judge_timing' => 'end_of_slam']);
        db_connect()->table('review_questions')->where('slam_id', $s['id'])->where('audience', 'user')->update(['max_score' => 5]);
        $this->at('2026-11-01 03:30:00', $s['id']);
        foreach ($s['users'] as $u) {
            $this->checkIn($u);
        }
        $this->at('2026-11-01 04:45:00', $s['id']);
        $this->at('2026-11-01 04:52:00', $s['id']);
        $this->at('2026-11-01 04:57:00', $s['id']);
        $this->at('2026-11-01 05:07:00', $s['id']);
        $this->at('2026-11-01 05:12:00', $s['id']);

        return $s;
    }

    private function csv(string $body): string
    {
        $f = tempnam(sys_get_temp_dir(), 'pm');
        file_put_contents($f, $body);

        return $f;
    }

    public function testManualAudienceBallotsCountInTheMarksAndRecalculate(): void
    {
        $s = $this->closedSlam();
        $id = $s['id'];
        $this->assertContains($this->phase($id), ['closed', 'marks_calculated']);
        $svc = service('paperMarks');
        $item = $s['items'][0];

        $rows = $svc->parseLines("# paper slips\n{$s['users'][0]['badge_id']}, 4, 5\n{$s['users'][1]['badge_id']} 3 3\n");
        $this->assertSame([['badge_id' => $s['users'][0]['badge_id'], 'scores' => ['4', '5'], 'line' => 2], ['badge_id' => $s['users'][1]['badge_id'], 'scores' => ['3', '3'], 'line' => 3]], $rows);
        $this->assertSame(['created' => 2, 'updated' => 0, 'unchanged' => 0], $svc->saveAudience($id, $item, $rows, $this->admin));
        $res = array_column(service('scoring')->compute($id)['results'], null, 'performance_id');
        $this->assertEqualsWithDelta(7.5, $res[$s['perfs'][0]]['avg_general'], 1e-9); // (9 + 6) / 2
        $this->assertSame('Automatic: marks entered', service('markRuns')->currentRun($id)['note']);

        // Entering the same person again changes the mark and keeps history.
        $again = $svc->parseLines("{$s['users'][1]['badge_id']}, 5, 5");
        $this->assertSame(['created' => 0, 'updated' => 1, 'unchanged' => 0], $svc->saveAudience($id, $item, $again, $this->admin));
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM review_revisions rr JOIN reviews r ON r.id = rr.review_id WHERE r.attendee_id = ?', [$s['users'][1]['id']]));

        // Bad lines reject everything, with line numbers.
        try {
            $svc->saveAudience($id, $item, $svc->parseLines("{$s['users'][2]['badge_id']}, 6, 2\nZZ00000, 1, 1\n{$s['users'][3]['badge_id']}, 2"), $this->admin);
            $this->fail('should reject');
        } catch (DomainException $e) {
            $this->assertCount(3, $e->details['rows']);
            $this->assertStringContainsString('Line 1', $e->details['rows'][0]);
        }
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM reviews WHERE attendee_id = ?', [$s['users'][2]['id']]));
    }

    public function testCsvImportForAudienceAndJudgesIsAllOrNothing(): void
    {
        $s = $this->closedSlam();
        $id = $s['id'];
        $svc = service('paperMarks');
        $name = Db::value('SELECT pr.name FROM performances p JOIN presenters pr ON pr.id = p.presenter_id WHERE p.id = ?', [$s['perfs'][1]]);

        $ok = $this->csv("badge_id,presenter,q1,q2\n{$s['users'][0]['badge_id']},\"{$name}\",5,4\n{$s['users'][1]['badge_id']},{$name},2,3\n");
        $this->assertSame(['rows' => 2, 'created' => 2, 'updated' => 0, 'judges' => 0], $svc->importCsv($id, 'audience', $ok, $this->admin));

        $bad = $this->csv("badge_id,presenter,q1,q2\n{$s['users'][2]['badge_id']},{$name},5,4\n{$s['users'][3]['badge_id']},Nobody Here,1,1\n");
        try {
            $svc->importCsv($id, 'audience', $bad, $this->admin);
            $this->fail('should reject');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Line 3', $e->details['rows'][0]);
        }
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM reviews WHERE attendee_id = ?', [$s['users'][2]['id']]), 'nothing imported from a file with an error');

        // Judges' marks (semicolon CSV, decimal comma).
        $j = $this->csv("badge_id;presenter;mark\n{$s['judges'][0]['badge_id']};{$name};7,5\n{$s['judges'][1]['badge_id']};{$name};8.5\n");
        $r = $svc->importCsv($id, 'judge', $j, $this->admin);
        $this->assertSame(2, $r['judges']);
        $res = array_column(service('scoring')->compute($id)['results'], null, 'performance_id');
        $this->assertEqualsWithDelta(8.0, $res[$s['perfs'][1]]['avg_judge'], 1e-9);
        $this->assertStringContainsString('badge_id,presenter,q1,q2', $svc->template('audience', $id));
        $this->assertStringContainsString('badge_id,presenter,mark', $svc->template('judge', $id));
    }
}
