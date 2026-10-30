<?php

declare(strict_types=1);

namespace Modules\Reviews\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReviews extends Migration
{
    public function up(): void
    {
        // slam_id NULL rows are default templates copied into each new slam.
        $this->db->query("CREATE TABLE review_questions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slam_id INT UNSIGNED NULL,
            audience ENUM('user','judge') NOT NULL,
            text VARCHAR(500) NOT NULL,
            text_ml VARCHAR(500) NULL,
            help_text VARCHAR(500) NULL,
            weight DECIMAL(5,2) NOT NULL DEFAULT 1.00,
            position INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            locked_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            KEY ix_rq_slam (slam_id, audience, position),
            CONSTRAINT fk_rq_slam FOREIGN KEY (slam_id) REFERENCES slams(id),
            CONSTRAINT ck_rq_weight CHECK (weight > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE reviews (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attendee_id INT UNSIGNED NOT NULL,
            schedule_item_id INT UNSIGNED NOT NULL,
            review_window_id INT UNSIGNED NOT NULL,
            comment VARCHAR(1000) NULL,
            submitted_at DATETIME NOT NULL,
            edited_at DATETIME NULL,
            edit_count INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uq_review_att_item (attendee_id, schedule_item_id),
            KEY ix_review_item (schedule_item_id),
            CONSTRAINT fk_review_att FOREIGN KEY (attendee_id) REFERENCES attendees(id),
            CONSTRAINT fk_review_item FOREIGN KEY (schedule_item_id) REFERENCES schedule_items(id),
            CONSTRAINT fk_review_window FOREIGN KEY (review_window_id) REFERENCES review_windows(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE review_answers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            review_id INT UNSIGNED NOT NULL,
            question_id INT UNSIGNED NOT NULL,
            score TINYINT NOT NULL,
            UNIQUE KEY uq_answer (review_id, question_id),
            CONSTRAINT fk_answer_review FOREIGN KEY (review_id) REFERENCES reviews(id),
            CONSTRAINT fk_answer_question FOREIGN KEY (question_id) REFERENCES review_questions(id),
            CONSTRAINT ck_answer_score CHECK (score BETWEEN 1 AND 10)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE review_revisions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            review_id INT UNSIGNED NOT NULL,
            question_id INT UNSIGNED NULL,
            old_score TINYINT NULL,
            new_score TINYINT NULL,
            old_comment VARCHAR(1000) NULL,
            new_comment VARCHAR(1000) NULL,
            revision_window_id INT UNSIGNED NOT NULL,
            changed_at DATETIME NOT NULL,
            KEY ix_rr_review (review_id),
            CONSTRAINT fk_rr_review FOREIGN KEY (review_id) REFERENCES reviews(id),
            CONSTRAINT fk_rr_window FOREIGN KEY (revision_window_id) REFERENCES revision_windows(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->forge->dropTable('review_revisions', true);
        $this->forge->dropTable('review_answers', true);
        $this->forge->dropTable('reviews', true);
        $this->forge->dropTable('review_questions', true);
    }
}
