<?php

declare(strict_types=1);

namespace Modules\Reviews\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Judges' marks: one-decimal scores (audience scores stay whole numbers, which
 * the application enforces), 1-mark criteria, a per-slam "Overall mark"
 * question for consolidated judging, and reviews entered by an admin.
 */
class JudgeCriteriaAndDecimals extends Migration
{
    private const CRITERIA = [
        ['Communication', 3, 'Effective for the general public: little or no jargon or technical terms, the overall idea is not lost, no false, pseudo or pseudoscientific information.'],
        ['Engagement', 3, 'An engaging story; a pause for a question and the audience\'s response to it; models, experiments, tricks, song, dance or anything else that draws the public in.'],
        ['Depth of knowledge', 3, 'Subject knowledge, novelty of the research idea, importance of the results and the way they are communicated. Give a lower mark for any wrong concept.'],
        ['Audience response', 1, 'Based on the audience\'s reactions (emoji or memes) during the talk.'],
    ];

    public function up(): void
    {
        $db = $this->db;
        $db->query("ALTER TABLE review_questions ADD COLUMN kind ENUM('criterion','total') NOT NULL DEFAULT 'criterion' AFTER audience,
            DROP CONSTRAINT ck_rq_max, ADD CONSTRAINT ck_rq_max CHECK (max_score BETWEEN 1 AND 10)");
        $db->query('ALTER TABLE review_answers DROP CONSTRAINT ck_answer_score');
        $db->query('ALTER TABLE review_answers MODIFY score DECIMAL(4,1) NOT NULL, ADD CONSTRAINT ck_answer_score CHECK (score > 0 AND score <= 10)');
        $db->query('ALTER TABLE review_revisions MODIFY old_score DECIMAL(4,1) NULL, MODIFY new_score DECIMAL(4,1) NULL, MODIFY revision_window_id INT UNSIGNED NULL');
        $db->query('ALTER TABLE reviews ADD COLUMN entered_by INT UNSIGNED NULL AFTER edit_count');

        // Judge templates still holding the seeded placeholders become the evaluation criteria.
        $placeholders = (int) $db->query("SELECT COUNT(*) n FROM review_questions WHERE slam_id IS NULL AND audience = 'judge' AND text LIKE '%(placeholder)%'")->getRow()->n;
        $all          = (int) $db->query("SELECT COUNT(*) n FROM review_questions WHERE slam_id IS NULL AND audience = 'judge'")->getRow()->n;
        if ($placeholders > 0 && $placeholders === $all) {
            $db->query("DELETE FROM review_questions WHERE slam_id IS NULL AND audience = 'judge'");
            foreach (self::CRITERIA as $i => [$text, $max, $help]) {
                $db->table('review_questions')->insert([
                    'slam_id' => null, 'audience' => 'judge', 'kind' => 'criterion', 'text' => $text, 'help_text' => $help, 'weight' => 1,
                    'max_score' => $max, 'position' => ($i + 1) * 10, 'is_active' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
        }
        // Every slam gets its "Overall mark" question for consolidated judging.
        $db->query("INSERT INTO review_questions (slam_id, audience, kind, text, help_text, weight, max_score, position, is_active, created_at, updated_at)
            SELECT s.id, 'judge', 'total', 'Overall mark', 'One consolidated mark out of 10.', 1, 10, 0, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM slams s
            WHERE NOT EXISTS (SELECT 1 FROM review_questions q WHERE q.slam_id = s.id AND q.kind = 'total')");
    }

    public function down(): void
    {
        $db = $this->db;
        $db->query("DELETE a FROM review_answers a JOIN review_questions q ON q.id = a.question_id WHERE q.kind = 'total'");
        $db->query("DELETE FROM review_questions WHERE kind = 'total'");
        // The old rule needs max_score >= 2 (1-mark criteria did not exist).
        $db->query('UPDATE review_questions SET max_score = 2 WHERE max_score < 2');
        $db->query('ALTER TABLE review_questions DROP CONSTRAINT ck_rq_max, DROP COLUMN kind, ADD CONSTRAINT ck_rq_max CHECK (max_score BETWEEN 2 AND 10)');
        $db->query('ALTER TABLE reviews DROP COLUMN entered_by');
        $db->query('ALTER TABLE review_answers DROP CONSTRAINT ck_answer_score');
        $db->query('UPDATE review_answers SET score = GREATEST(1, ROUND(score))');
        $db->query('ALTER TABLE review_answers MODIFY score TINYINT NOT NULL, ADD CONSTRAINT ck_answer_score CHECK (score BETWEEN 1 AND 10)');
        $db->query('ALTER TABLE review_revisions MODIFY old_score TINYINT NULL, MODIFY new_score TINYINT NULL');
        // revision_window_id stays nullable: admin-entered judge marks may have none.
    }
}
