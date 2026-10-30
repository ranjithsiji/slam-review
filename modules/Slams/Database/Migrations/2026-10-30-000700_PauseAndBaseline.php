<?php

declare(strict_types=1);

namespace Modules\Slams\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Pausing a running slam and measuring drift against the original plan:
 * - slams.paused_at / pause_reason and a history of pauses (slam_pauses);
 * - schedule_items.original_start_at: the plan when the slam went live, never
 *   changed by pauses or shifts, so "12 min behind" always means behind that plan.
 */
class PauseAndBaseline extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE slams ADD COLUMN paused_at DATETIME NULL, ADD COLUMN pause_reason VARCHAR(255) NULL');
        $this->db->query('ALTER TABLE schedule_items ADD COLUMN original_start_at DATETIME NULL AFTER planned_start_at');
        $this->db->query('UPDATE schedule_items SET original_start_at = planned_start_at WHERE original_start_at IS NULL');
        $this->db->query("CREATE TABLE slam_pauses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slam_id INT UNSIGNED NOT NULL,
            paused_at DATETIME NOT NULL,
            resumed_at DATETIME NULL,
            seconds INT UNSIGNED NULL,
            reason VARCHAR(255) NULL,
            paused_by INT UNSIGNED NULL,
            resumed_by INT UNSIGNED NULL,
            shifted TEXT NULL,
            KEY ix_sp_slam (slam_id, paused_at),
            CONSTRAINT fk_sp_slam FOREIGN KEY (slam_id) REFERENCES slams(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->forge->dropTable('slam_pauses', true);
        $this->db->query('ALTER TABLE schedule_items DROP COLUMN original_start_at');
        $this->db->query('ALTER TABLE slams DROP COLUMN pause_reason, DROP COLUMN paused_at');
    }
}
