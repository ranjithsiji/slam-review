<?php

declare(strict_types=1);

namespace Modules\Scoring\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateScoring extends Migration
{
    public function up(): void
    {
        $this->db->query("CREATE TABLE score_config (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slam_id INT UNSIGNED NOT NULL,
            w_user DECIMAL(5,2) NOT NULL DEFAULT 40,
            w_judge DECIMAL(5,2) NOT NULL DEFAULT 40,
            w_complete_reviewer DECIMAL(5,2) NOT NULL DEFAULT 20,
            updated_by INT UNSIGNED NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_sc_slam (slam_id),
            CONSTRAINT fk_sc_slam FOREIGN KEY (slam_id) REFERENCES slams(id),
            CONSTRAINT ck_sc_sum CHECK (w_user + w_judge + w_complete_reviewer = 100)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE mark_runs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slam_id INT UNSIGNED NOT NULL,
            calculated_at DATETIME NOT NULL,
            calculated_by INT UNSIGNED NULL,
            weights_snapshot LONGTEXT NOT NULL,
            question_weights_snapshot LONGTEXT NOT NULL,
            counts_snapshot LONGTEXT NOT NULL,
            is_current TINYINT(1) NOT NULL DEFAULT 0,
            is_final TINYINT(1) NOT NULL DEFAULT 0,
            note VARCHAR(255) NULL,
            KEY ix_mr_slam (slam_id, is_current),
            CONSTRAINT fk_mr_slam FOREIGN KEY (slam_id) REFERENCES slams(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE mark_results (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            mark_run_id INT UNSIGNED NOT NULL,
            performance_id INT UNSIGNED NOT NULL,
            avg_user DECIMAL(8,4) NULL,
            avg_judge DECIMAL(8,4) NULL,
            avg_complete DECIMAL(8,4) NULL,
            final_mark DECIMAL(6,4) NULL,
            `rank` INT UNSIGNED NULL,
            n_user INT UNSIGNED NOT NULL DEFAULT 0,
            n_judge INT UNSIGNED NOT NULL DEFAULT 0,
            n_complete INT UNSIGNED NOT NULL DEFAULT 0,
            per_question LONGTEXT NOT NULL,
            KEY ix_mres_run (mark_run_id),
            CONSTRAINT fk_mres_run FOREIGN KEY (mark_run_id) REFERENCES mark_runs(id),
            CONSTRAINT fk_mres_perf FOREIGN KEY (performance_id) REFERENCES performances(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->forge->dropTable('mark_results', true);
        $this->forge->dropTable('mark_runs', true);
        $this->forge->dropTable('score_config', true);
    }
}
