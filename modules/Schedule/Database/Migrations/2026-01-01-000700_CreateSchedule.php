<?php

declare(strict_types=1);

namespace Modules\Schedule\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSchedule extends Migration
{
    public function up(): void
    {
        // actual_start_at / ends_at are used by breaks (a break has no review window).
        $this->db->query("CREATE TABLE schedule_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slam_id INT UNSIGNED NOT NULL,
            item_type ENUM('performance','break') NOT NULL DEFAULT 'performance',
            break_type ENUM('tea','lunch','other') NULL,
            performance_id INT UNSIGNED NULL,
            label VARCHAR(150) NULL,
            position INT NOT NULL,
            planned_start_at DATETIME NOT NULL,
            duration_minutes SMALLINT UNSIGNED NOT NULL,
            is_pinned TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('pending','open','closed','skipped') NOT NULL DEFAULT 'pending',
            actual_start_at DATETIME NULL,
            ends_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            KEY ix_si_slam (slam_id, position),
            KEY ix_si_perf (performance_id),
            CONSTRAINT fk_si_slam FOREIGN KEY (slam_id) REFERENCES slams(id),
            CONSTRAINT fk_si_perf FOREIGN KEY (performance_id) REFERENCES performances(id),
            CONSTRAINT ck_si_type CHECK ((item_type = 'performance' AND performance_id IS NOT NULL) OR (item_type = 'break' AND performance_id IS NULL AND break_type IS NOT NULL)),
            CONSTRAINT ck_si_duration CHECK (duration_minutes > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE schedule_snapshots (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slam_id INT UNSIGNED NOT NULL,
            version INT UNSIGNED NOT NULL,
            saved_at DATETIME NOT NULL,
            saved_by INT UNSIGNED NULL,
            note VARCHAR(255) NULL,
            snapshot LONGTEXT NOT NULL,
            UNIQUE KEY uq_ss_version (slam_id, version),
            CONSTRAINT fk_ss_slam FOREIGN KEY (slam_id) REFERENCES slams(id),
            CONSTRAINT ck_ss_json CHECK (JSON_VALID(snapshot))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->forge->dropTable('schedule_snapshots', true);
        $this->forge->dropTable('schedule_items', true);
    }
}
