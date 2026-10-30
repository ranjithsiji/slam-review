<?php

declare(strict_types=1);

namespace Modules\Checkin\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCheckins extends Migration
{
    public function up(): void
    {
        // open_marker is 1 while the check-in is open and NULL once closed, so the
        // unique key allows at most one open check-in per attendee (NULLs never collide).
        // session_token_hash binds the check-in to one device; NULL means a volunteer
        // or admin checked the person in and their phone has not claimed it yet.
        $this->db->query("CREATE TABLE checkins (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attendee_id INT UNSIGNED NOT NULL,
            checked_in_at DATETIME NOT NULL,
            checked_out_at DATETIME NULL,
            checked_in_by_type ENUM('self','volunteer','admin') NOT NULL DEFAULT 'self',
            checked_in_by INT UNSIGNED NULL,
            checked_out_by_type ENUM('self','volunteer','admin','system') NULL,
            checked_out_by INT UNSIGNED NULL,
            checkout_type ENUM('manual','forced','auto') NULL,
            checkout_reason VARCHAR(255) NULL,
            is_late_override TINYINT(1) NOT NULL DEFAULT 0,
            session_token_hash CHAR(64) NULL,
            open_marker TINYINT(1) NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_checkin_open (attendee_id, open_marker),
            KEY ix_checkin_att (attendee_id, checked_out_at),
            CONSTRAINT fk_checkin_att FOREIGN KEY (attendee_id) REFERENCES attendees(id),
            CONSTRAINT ck_checkin_open CHECK ((checked_out_at IS NULL AND open_marker = 1) OR (checked_out_at IS NOT NULL AND open_marker IS NULL))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->forge->dropTable('checkins', true);
    }
}
