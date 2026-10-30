<?php

declare(strict_types=1);

namespace Modules\Reviews\Services;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Modules\Slams\Services\ReviewWindowService;

/**
 * Review submission (during a performance's open window) and revision (during
 * the slam's edit window). Every check uses the server clock at the moment of
 * the request; the cron only tidies up.
 */
class ReviewService
{
    /**
     * Validate answers: exactly the active questions for the audience, integers 1–10.
     *
     * @param list<array{question_id: mixed, score: mixed}>|mixed $answers
     * @return array<int, int> question_id => score
     */
    public function validateAnswers(int $slamId, string $audience, mixed $answers): array
    {
        if (! is_array($answers)) {
            throw new DomainException('invalid_score', 'Answers are missing.');
        }
        $expected = array_map(static fn ($q) => (int) $q['id'], service('questions')->active($slamId, $audience));
        $out      = [];
        foreach ($answers as $a) {
            if (! is_array($a) || ! isset($a['question_id'], $a['score'])) {
                throw new DomainException('invalid_score', 'Each answer needs a question and a score.');
            }
            $qid   = filter_var($a['question_id'], FILTER_VALIDATE_INT);
            $score = filter_var($a['score'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
            if ($qid === false || $score === false) {
                throw new DomainException('invalid_score', 'Every score must be a whole number from 1 to 10.');
            }
            if (isset($out[$qid])) {
                throw new DomainException('invalid_score', 'A question was answered twice.');
            }
            $out[$qid] = $score;
        }
        $given = array_keys($out);
        sort($given);
        sort($expected);
        if ($given !== $expected) {
            throw new DomainException('invalid_score', 'Please answer every question (and only the questions shown).');
        }

        return $out;
    }

    private static function cleanComment(mixed $c): ?string
    {
        $c = trim((string) ($c ?? ''));

        return $c === '' ? null : mb_substr($c, 0, 1000);
    }

    /**
     * Submit a review for the open performance. Idempotent: a repeat for the same
     * performance returns the stored review with already=true.
     *
     * @param array{id:int, slam_id:int, badge_id:string, user_type:string} $attendee
     * @return array{review_id: int, already: bool}
     */
    public function submit(array $attendee, int $itemId, mixed $answers, mixed $comment, Actor $actor): array
    {
        $slamId = (int) $attendee['slam_id'];
        service('slamPhase')->tick($slamId);
        try {
            $result = Db::tx(function ($db) use ($attendee, $itemId, $answers, $comment, $actor, $slamId): array {
                $slam = Db::row('SELECT * FROM slams WHERE id = ?', [$slamId]);
                $existing = Db::row('SELECT id FROM reviews WHERE attendee_id = ? AND schedule_item_id = ?', [$attendee['id'], $itemId]);
                if ($existing !== null) {
                    return ['review_id' => (int) $existing['id'], 'already' => true];
                }
                if (Db::value('SELECT id FROM checkins WHERE attendee_id = ? AND checked_out_at IS NULL', [$attendee['id']]) === null) {
                    throw new DomainException('not_checked_in', 'You are not checked in.', 403);
                }
                if ($slam['status'] !== 'reviewing') {
                    throw new DomainException('no_open_window', 'Reviews are not open right now.', 409);
                }
                // Lock the window row so a concurrent close cannot interleave.
                $window = Db::row(
                    "SELECT rw.* FROM review_windows rw JOIN schedule_items si ON si.id = rw.schedule_item_id
                     WHERE rw.schedule_item_id = ? AND si.slam_id = ? AND si.item_type = 'performance' AND rw.closed_at IS NULL FOR UPDATE",
                    [$itemId, $slamId],
                );
                if ($window === null) {
                    $ran = Db::value('SELECT id FROM review_windows WHERE schedule_item_id = ? AND closed_at IS NOT NULL LIMIT 1', [$itemId]) !== null;
                    throw $ran
                        ? new DomainException('window_closed', 'The review window has closed.', 409)
                        : new DomainException('no_open_window', 'This performance is not open for review.', 409);
                }
                if (Clock::now() >= Clock::parse(ReviewWindowService::effectiveClose($window, $slam))) {
                    throw new DomainException('window_closed', 'The review window has closed.', 409);
                }
                $scores = $this->validateAnswers($slamId, $attendee['user_type'], $answers);
                $now    = Clock::db();
                $db->table('reviews')->insert([
                    'attendee_id' => $attendee['id'], 'schedule_item_id' => $itemId, 'review_window_id' => $window['id'],
                    'comment' => self::cleanComment($comment), 'submitted_at' => $now,
                ]);
                $rid = (int) $db->insertID();
                $db->table('review_answers')->insertBatch(array_map(
                    static fn ($q, $s) => ['review_id' => $rid, 'question_id' => $q, 'score' => $s],
                    array_keys($scores),
                    $scores,
                ));
                service('questions')->lockAudience($slamId, $attendee['user_type'], $actor);
                service('activityLog')->log($actor, 'review.submitted', [
                    'slam_id' => $slamId, 'target_type' => 'schedule_item', 'target_id' => $itemId,
                    'meta' => ['review_id' => $rid, 'answers' => $scores, 'has_comment' => self::cleanComment($comment) !== null],
                ]);

                return ['review_id' => $rid, 'already' => false];
            });
        } catch (DomainException $e) {
            service('activityLog')->log($actor, 'review.rejected', ['slam_id' => $slamId, 'target_type' => 'schedule_item', 'target_id' => $itemId, 'outcome' => 'denied', 'reason' => $e->reason]);
            throw $e;
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            // Unique key race (double tap on two connections): treat as already submitted.
            $existing = Db::row('SELECT id FROM reviews WHERE attendee_id = ? AND schedule_item_id = ?', [$attendee['id'], $itemId]);
            if ($existing !== null) {
                return ['review_id' => (int) $existing['id'], 'already' => true];
            }
            throw $e;
        }
        return $result;
    }

    /**
     * Revise own review during the edit window. Changes write review_revisions rows.
     *
     * @param array{id:int, slam_id:int, user_type:string} $attendee
     */
    public function revise(array $attendee, int $reviewId, mixed $answers, mixed $comment, Actor $actor): array
    {
        $slamId = (int) $attendee['slam_id'];
        service('slamPhase')->tick($slamId);
        try {
            return Db::tx(function ($db) use ($attendee, $reviewId, $answers, $comment, $actor, $slamId): array {
                $slam   = Db::row('SELECT * FROM slams WHERE id = ?', [$slamId]);
                $review = Db::row('SELECT * FROM reviews WHERE id = ? FOR UPDATE', [$reviewId]);
                if ($review === null || (int) $review['attendee_id'] !== (int) $attendee['id']) {
                    throw new DomainException('edit_not_allowed', 'Review not found.', 404);
                }
                if (Db::value('SELECT id FROM checkins WHERE attendee_id = ? AND checked_out_at IS NULL', [$attendee['id']]) === null) {
                    throw new DomainException('not_checked_in', 'You are not checked in.', 403);
                }
                if (in_array($slam['status'], ['revision_closed', 'closed', 'marks_calculated', 'ended'], true)) {
                    throw new DomainException('scores_locked', 'Scores are locked.', 409);
                }
                $rw = service('slamPhase')->windows()->openRevisionWindow($slamId);
                if ($slam['status'] !== 'revision' || $rw === null || Clock::now() >= Clock::parse(ReviewWindowService::effectiveClose($rw, $slam))) {
                    throw new DomainException('edit_not_allowed', 'Scores can only be changed while the edit window is open.', 409);
                }
                $scores = $this->validateAnswers($slamId, $attendee['user_type'], $answers);
                $old    = [];
                foreach (Db::rows('SELECT question_id, score FROM review_answers WHERE review_id = ?', [$reviewId]) as $a) {
                    $old[(int) $a['question_id']] = (int) $a['score'];
                }
                $now     = Clock::db();
                $changes = [];
                foreach ($scores as $qid => $score) {
                    if (($old[$qid] ?? null) !== $score) {
                        $db->table('review_answers')->where('review_id', $reviewId)->where('question_id', $qid)->update(['score' => $score]);
                        $db->table('review_revisions')->insert([
                            'review_id' => $reviewId, 'question_id' => $qid, 'old_score' => $old[$qid] ?? null, 'new_score' => $score,
                            'revision_window_id' => $rw['id'], 'changed_at' => $now,
                        ]);
                        $changes[$qid] = ['old' => $old[$qid] ?? null, 'new' => $score];
                    }
                }
                $newComment = self::cleanComment($comment);
                if ($newComment !== $review['comment']) {
                    $db->table('review_revisions')->insert([
                        'review_id' => $reviewId, 'question_id' => null, 'old_comment' => $review['comment'], 'new_comment' => $newComment,
                        'revision_window_id' => $rw['id'], 'changed_at' => $now,
                    ]);
                    $changes['comment'] = true;
                }
                if ($changes === []) {
                    return ['changed' => false];
                }
                $db->table('reviews')->where('id', $reviewId)->update(['comment' => $newComment, 'edited_at' => $now, 'edit_count' => (int) $review['edit_count'] + 1]);
                service('activityLog')->log($actor, 'review.revised', [
                    'slam_id' => $slamId, 'target_type' => 'review', 'target_id' => $reviewId, 'meta' => ['changes' => $changes],
                ]);

                return ['changed' => true];
            });
        } catch (DomainException $e) {
            service('activityLog')->log($actor, 'review.rejected', ['slam_id' => $slamId, 'target_type' => 'review', 'target_id' => $reviewId, 'outcome' => 'denied', 'reason' => $e->reason]);
            throw $e;
        }
    }

    /** A reviewer's own reviews with scores (status page and edit list). */
    public function myReviews(array $attendee): array
    {
        $rows = Db::rows(
            "SELECT r.id, r.schedule_item_id, r.comment, r.submitted_at, r.edited_at, r.edit_count, p.topic, pr.name AS presenter_name, si.position
             FROM reviews r JOIN schedule_items si ON si.id = r.schedule_item_id
             JOIN performances p ON p.id = si.performance_id JOIN presenters pr ON pr.id = p.presenter_id
             WHERE r.attendee_id = ? ORDER BY si.position",
            [$attendee['id']],
        );
        $answers = [];
        if ($rows !== []) {
            $ids = implode(',', array_map(static fn ($r) => (int) $r['id'], $rows));
            foreach (Db::rows("SELECT review_id, question_id, score FROM review_answers WHERE review_id IN ({$ids})") as $a) {
                $answers[(int) $a['review_id']][] = ['question_id' => (int) $a['question_id'], 'score' => (int) $a['score']];
            }
        }

        return array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'schedule_item_id' => (int) $r['schedule_item_id'], 'presenter_name' => $r['presenter_name'],
            'topic' => $r['topic'], 'comment' => $r['comment'], 'submitted_at' => Clock::iso($r['submitted_at']),
            'edited_at' => Clock::iso($r['edited_at']), 'edit_count' => (int) $r['edit_count'], 'answers' => $answers[(int) $r['id']] ?? [],
        ], $rows);
    }
}
