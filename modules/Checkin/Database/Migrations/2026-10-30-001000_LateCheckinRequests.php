<?php

declare(strict_types=1);

namespace Modules\Checkin\Database\Migrations;

use CodeIgniter\Database\Migration;

/** A phone waiting at the "ask the volunteer" screen after Gate Close; the volunteer's check-in binds it. */
class LateCheckinRequests extends Migration
{
    public function up(): void
    {
        $this->db->query("CREATE TABLE late_checkin_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slam_id INT UNSIGNED NOT NULL,
            attendee_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            code CHAR(3) NOT NULL,
            status ENUM('waiting','approved','cancelled','expired') NOT NULL DEFAULT 'waiting',
            checkin_id INT UNSIGNED NULL,
            approved_by_type ENUM('volunteer','admin') NULL,
            approved_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            seen_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            approved_at DATETIME NULL,
            KEY ix_lcr_slam (slam_id, status, seen_at),
            KEY ix_lcr_att (attendee_id, status),
            CONSTRAINT fk_lcr_slam FOREIGN KEY (slam_id) REFERENCES slams(id),
            CONSTRAINT fk_lcr_att FOREIGN KEY (attendee_id) REFERENCES attendees(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->forge->dropTable('late_checkin_requests', true);
    }
}
