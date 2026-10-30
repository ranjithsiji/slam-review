<?php

declare(strict_types=1);

namespace Modules\Reviews\Services;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/**
 * Review questions per slam and audience (user / judge), plus default templates
 * (slam_id NULL) that new slams copy. Once the first review of an audience is
 * submitted in a slam, that audience's questions lock: only wording fixes.
 *
 * Judges have two kinds of question: the evaluation criteria (kind criterion,
 * used in live detailed judging) and one "Overall mark" out of 10 per slam
 * (kind total, used for consolidated judging, live or entered at the end).
 */
class QuestionService
{
    public const AUDIENCES = ['user', 'judge'];

    /** @return list<array<string, mixed>> */
    public function list(?int $slamId, string $audience, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM review_questions WHERE ' . ($slamId === null ? 'slam_id IS NULL' : 'slam_id = ?') . " AND audience = ? AND kind = 'criterion'"
            . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY position, id';

        return Db::rows($sql, $slamId === null ? [$audience] : [$slamId, $audience]);
    }

    /**
     * The questions a reviewer of this audience answers, in display order. For
     * judges that is the criteria in live detailed judging, otherwise the
     * slam's single "Overall mark" question.
     */
    public function active(int $slamId, string $audience): array
    {
        if ($audience === 'judge' && service('scoring')->judging($slamId)['consolidated']) {
            return [$this->totalQuestion($slamId)];
        }

        return $this->list($slamId, $audience, true);
    }

    /** The slam's "Overall mark" judge question (created on demand for slams made before it existed). */
    public function totalQuestion(int $slamId): array
    {
        $q = Db::row("SELECT * FROM review_questions WHERE slam_id = ? AND kind = 'total' LIMIT 1", [$slamId]);
        if ($q === null) {
            db_connect()->table('review_questions')->insert(self::totalRow($slamId));
            $q = Db::row("SELECT * FROM review_questions WHERE slam_id = ? AND kind = 'total' LIMIT 1", [$slamId]);
        }

        return $q;
    }

    private static function totalRow(int $slamId): array
    {
        return [
            'slam_id' => $slamId, 'audience' => 'judge', 'kind' => 'total', 'text' => 'Overall mark', 'help_text' => 'One consolidated mark out of 10.',
            'weight' => 1, 'max_score' => 10, 'position' => 0, 'is_active' => 1, 'created_at' => Clock::db(), 'updated_at' => Clock::db(),
        ];
    }

    /** Judges may give one decimal place (minimum 0.1); the audience gives whole marks from 1. */
    public static function step(string $audience): float
    {
        return $audience === 'judge' ? 0.1 : 1.0;
    }

    public function isLocked(?int $slamId, string $audience): bool
    {
        if ($slamId === null) {
            return false;
        }

        return Db::value('SELECT id FROM review_questions WHERE slam_id = ? AND audience = ? AND locked_at IS NOT NULL LIMIT 1', [$slamId, $audience]) !== null
            || Db::value('SELECT r.id FROM reviews r JOIN attendees a ON a.id = r.attendee_id WHERE a.slam_id = ? AND a.user_type = ? LIMIT 1', [$slamId, $audience]) !== null;
    }

    /** Called inside the review-submit transaction on the first review of an audience. */
    public function lockAudience(int $slamId, string $audience, Actor $actor): void
    {
        $n = db_connect()->table('review_questions')->where('slam_id', $slamId)->where('audience', $audience)->where('locked_at', null)
            ->update(['locked_at' => Clock::db()]);
        if (db_connect()->affectedRows() > 0) {
            service('activityLog')->log($actor, 'question.locked', ['slam_id' => $slamId, 'meta' => ['audience' => $audience]]);
        }
    }

    public function copyTemplatesInto(int $slamId): void
    {
        $db = db_connect();
        foreach (Db::rows('SELECT * FROM review_questions WHERE slam_id IS NULL AND is_active = 1 ORDER BY audience, position, id') as $q) {
            $db->table('review_questions')->insert([
                'slam_id' => $slamId, 'audience' => $q['audience'], 'text' => $q['text'], 'text_ml' => $q['text_ml'],
                'help_text' => $q['help_text'], 'weight' => $q['weight'], 'max_score' => $q['max_score'], 'position' => $q['position'], 'is_active' => 1,
                'created_at' => Clock::db(), 'updated_at' => Clock::db(),
            ]);
        }
        $db->table('review_questions')->insert(self::totalRow($slamId));
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    private function validate(array $in, bool $textOnly): array
    {
        $e    = [];
        $text = trim((string) ($in['text'] ?? ''));
        if (mb_strlen($text) < 2 || mb_strlen($text) > 500) {
            $e['text'] = 'Question text must be 2–500 characters.';
        }
        $data = [
            'text'      => $text,
            'text_ml'   => trim((string) ($in['text_ml'] ?? '')) ?: null,
            'help_text' => trim((string) ($in['help_text'] ?? '')) ?: null,
        ];
        if (! $textOnly) {
            $w = $in['weight'] ?? 1;
            if (! is_numeric($w) || (float) $w <= 0 || (float) $w > 100) {
                $e['weight'] = 'Weight must be a number greater than 0 (max 100).';
            }
            $m = $in['max_score'] ?? 10;
            if (filter_var($m, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]) === false) {
                $e['max_score'] = 'Maximum marks must be a whole number from 1 to 10.';
            }
            $data['weight']    = round((float) $w, 2);
            $data['max_score'] = (int) $m;
            $data['is_active'] = array_key_exists('is_active', $in) ? (int) (bool) $in['is_active'] : 1;
        }
        if ($e !== []) {
            throw new DomainException('validation', 'Please correct the highlighted fields.', 422, $e);
        }

        return $data;
    }

    public function create(?int $slamId, string $audience, array $in, Actor $actor): int
    {
        if (! in_array($audience, self::AUDIENCES, true)) {
            throw new DomainException('validation', 'Audience must be "user" (judges) or "judge" (experts).');
        }
        if ($this->isLocked($slamId, $audience)) {
            throw new DomainException('questions_locked', 'Reviews have been submitted; questions can no longer be added.', 409);
        }
        $data = $this->validate($in + ['max_score' => self::defaultMax($audience)], false);
        $pos  = (int) Db::value('SELECT COALESCE(MAX(position), 0) FROM review_questions WHERE ' . ($slamId === null ? 'slam_id IS NULL' : 'slam_id = ' . (int) $slamId) . ' AND audience = ?', [$audience]) + 10;
        $db   = db_connect();
        $db->table('review_questions')->insert($data + ['slam_id' => $slamId, 'audience' => $audience, 'position' => $pos, 'created_at' => Clock::db(), 'updated_at' => Clock::db()]);
        $id = (int) $db->insertID();
        service('activityLog')->log($actor, $slamId === null ? 'question.template_updated' : 'question.created', ['slam_id' => $slamId, 'target_type' => 'question', 'target_id' => $id, 'new' => $data + ['audience' => $audience]]);

        return $id;
    }

    public function update(int $id, array $in, Actor $actor): void
    {
        $q      = Db::row('SELECT * FROM review_questions WHERE id = ?', [$id]) ?? throw new DomainException('not_found', 'Question not found.', 404);
        $slamId = $q['slam_id'] !== null ? (int) $q['slam_id'] : null;
        $locked = $this->isLocked($slamId, $q['audience']);
        $data   = $this->validate($in + ['weight' => $q['weight'], 'max_score' => $q['max_score']], $locked);
        if ($locked) {
            $data = array_intersect_key($data, array_flip(['text', 'text_ml', 'help_text']));
        }
        [$old, $new] = \Modules\ActivityLog\Services\ActivityLogService::diff($q, $data);
        if ($new === []) {
            return;
        }
        db_connect()->table('review_questions')->where('id', $id)->update($data + ['updated_at' => Clock::db()]);
        $action = $slamId === null ? 'question.template_updated'
            : (isset($new['weight']) && count($new) === 1 ? 'question.reweighted'
                : (isset($new['is_active']) && (int) $new['is_active'] === 0 ? 'question.deactivated' : 'question.updated'));
        service('activityLog')->log($actor, $action, ['slam_id' => $slamId, 'target_type' => 'question', 'target_id' => $id, 'old' => $old, 'new' => $new]);
    }

    public function delete(int $id, Actor $actor): void
    {
        $q = Db::row('SELECT * FROM review_questions WHERE id = ?', [$id]) ?? throw new DomainException('not_found', 'Question not found.', 404);
        if ($this->isLocked($q['slam_id'] !== null ? (int) $q['slam_id'] : null, $q['audience'])
            || (int) Db::value('SELECT COUNT(*) FROM review_answers WHERE question_id = ?', [$id]) > 0) {
            throw new DomainException('questions_locked', 'Reviews have been submitted; this question cannot be removed.', 409);
        }
        db_connect()->table('review_questions')->where('id', $id)->delete();
        service('activityLog')->log($actor, 'question.deleted', ['slam_id' => $q['slam_id'], 'target_type' => 'question', 'target_id' => $id, 'old' => ['text' => $q['text']]]);
    }

    /** @param list<int> $ids new order */
    public function reorder(?int $slamId, string $audience, array $ids, Actor $actor): void
    {
        $current = array_map(static fn ($r) => (int) $r['id'], $this->list($slamId, $audience));
        sort($current);
        $given = array_map('intval', $ids);
        $check = $given;
        sort($check);
        if ($check !== $current) {
            throw new DomainException('validation', 'The new order must list every question once.');
        }
        Db::tx(function ($db) use ($given): void {
            foreach ($given as $i => $id) {
                $db->table('review_questions')->where('id', $id)->update(['position' => ($i + 1) * 10]);
            }
        });
        service('activityLog')->log($actor, 'question.reordered', ['slam_id' => $slamId, 'meta' => ['audience' => $audience, 'order' => $given]]);
    }

    /** Replace this slam's questions for both audiences (unlocked ones) with another slam's. */
    public function copyFromSlam(int $fromSlamId, int $toSlamId, Actor $actor): int
    {
        $copied = 0;
        Db::tx(function ($db) use ($fromSlamId, $toSlamId, &$copied): void {
            foreach (self::AUDIENCES as $aud) {
                if ($this->isLocked($toSlamId, $aud)) {
                    continue;
                }
                $db->table('review_questions')->where('slam_id', $toSlamId)->where('audience', $aud)->where('kind', 'criterion')->delete();
                foreach ($this->list($fromSlamId, $aud, true) as $q) {
                    $db->table('review_questions')->insert([
                        'slam_id' => $toSlamId, 'audience' => $aud, 'text' => $q['text'], 'text_ml' => $q['text_ml'], 'help_text' => $q['help_text'],
                        'weight' => $q['weight'], 'max_score' => $q['max_score'], 'position' => $q['position'], 'is_active' => 1, 'created_at' => Clock::db(), 'updated_at' => Clock::db(),
                    ]);
                    $copied++;
                }
            }
        });
        service('activityLog')->log($actor, 'question.copied_from_slam', ['slam_id' => $toSlamId, 'meta' => ['from_slam_id' => $fromSlamId, 'copied' => $copied]]);

        return $copied;
    }

    /** Audience questions are worth 5 points each (2 × 5 = 10); judge questions 10. */
    public static function defaultMax(string $audience): int
    {
        return $audience === 'user' ? 5 : 10;
    }

    /** Client-safe question list for the reviewer screen. */
    public static function forClient(array $rows): array
    {
        return array_map(static fn ($q) => [
            'id' => (int) $q['id'], 'text' => $q['text'], 'text_ml' => $q['text_ml'], 'help_text' => $q['help_text'], 'max_score' => (int) $q['max_score'],
            'step' => self::step($q['audience']),
        ], $rows);
    }
}
