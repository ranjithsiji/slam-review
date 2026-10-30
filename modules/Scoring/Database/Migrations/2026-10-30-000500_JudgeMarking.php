<?php

declare(strict_types=1);

namespace Modules\Scoring\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * How judges' marks reach the system, per slam:
 *   judge_timing  end_of_slam (default): an admin enters each judge's consolidated mark after the reviews close;
 *                 live: judges score on their phones during each review window.
 *   judge_mode    live only: detailed (the evaluation criteria) or consolidated (one mark out of 10).
 */
class JudgeMarking extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE score_config ADD COLUMN judge_timing ENUM('live','end_of_slam') NOT NULL DEFAULT 'end_of_slam' AFTER representative_count,
            ADD COLUMN judge_mode ENUM('detailed','consolidated') NOT NULL DEFAULT 'detailed' AFTER judge_timing");
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE score_config DROP COLUMN judge_mode, DROP COLUMN judge_timing');
    }
}
