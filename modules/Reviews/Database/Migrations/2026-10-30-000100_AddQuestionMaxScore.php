<?php

declare(strict_types=1);

namespace Modules\Reviews\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Each question has its own maximum score (answers are 1..max_score). Audience
 * questions default to 5 (two questions × 5 = 10); judge questions to 10. The
 * per-review mark is always normalised to 10.
 */
class AddQuestionMaxScore extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE review_questions ADD COLUMN max_score TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER weight,
            ADD CONSTRAINT ck_rq_max CHECK (max_score BETWEEN 2 AND 10)');
        // Unanswered audience questions move to the new 5-point default.
        $this->db->query("UPDATE review_questions q SET q.max_score = 5 WHERE q.audience = 'user'
            AND NOT EXISTS (SELECT 1 FROM review_answers a WHERE a.question_id = q.id)");
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE review_questions DROP CONSTRAINT ck_rq_max, DROP COLUMN max_score');
    }
}
