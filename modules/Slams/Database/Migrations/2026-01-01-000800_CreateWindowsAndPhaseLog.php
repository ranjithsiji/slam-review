<?php

declare(strict_types=1);

namespace Modules\Slams\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWindowsAndPhaseLog extends Migration
{
    public function up(): void
    {
        // effective close = closes_at + extended_minutes_total (capped at slams.closes_at)
        $this->db->query("CREATE TABLE review_windows (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            schedule_item_id INT UNSIGNED NOT NULL,
            opened_at DATETIME NOT NULL,
            closes_at DATETIME NOT NULL,
            extended_minutes_total INT NOT NULL DEFAULT 0,
            closed_at DATETIME NULL,
            closed_reason ENUM('timer','manual','hard_stop') NULL,
            KEY ix_rw_item (schedule_item_id),
            CONSTRAINT fk_rw_item FOREIGN KEY (schedule_item_id) REFERENCES schedule_items(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE revision_windows (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slam_id INT UNSIGNED NOT NULL,
            opened_at DATETIME NOT NULL,
            closes_at DATETIME NOT NULL,
            extended_minutes_total INT NOT NULL DEFAULT 0,
            closed_at DATETIME NULL,
            closed_reason ENUM('timer','manual','hard_stop') NULL,
            KEY ix_rvw_slam (slam_id),
            CONSTRAINT fk_rvw_slam FOREIGN KEY (slam_id) REFERENCES slams(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE review_extensions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            review_window_id INT UNSIGNED NULL,
            revision_window_id INT UNSIGNED NULL,
            minutes INT NOT NULL,
            by_admin_id INT UNSIGNED NULL,
            at DATETIME NOT NULL,
            CONSTRAINT fk_re_rw FOREIGN KEY (review_window_id) REFERENCES review_windows(id),
            CONSTRAINT fk_re_rvw FOREIGN KEY (revision_window_id) REFERENCES revision_windows(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE slam_phase_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slam_id INT UNSIGNED NOT NULL,
            from_status VARCHAR(30) NOT NULL,
            to_status VARCHAR(30) NOT NULL,
            changed_at DATETIME NOT NULL,
            changed_by_type ENUM('admin','system') NOT NULL,
            changed_by_id INT UNSIGNED NULL,
            `trigger` ENUM('timer','auto','manual_override','hard_stop') NOT NULL,
            note VARCHAR(500) NULL,
            KEY ix_spl_slam (slam_id, changed_at),
            CONSTRAINT fk_spl_slam FOREIGN KEY (slam_id) REFERENCES slams(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->forge->dropTable('slam_phase_log', true);
        $this->forge->dropTable('review_extensions', true);
        $this->forge->dropTable('revision_windows', true);
        $this->forge->dropTable('review_windows', true);
    }
}
