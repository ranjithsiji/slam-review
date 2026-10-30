<?php

declare(strict_types=1);

namespace Modules\Slams\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSlams extends Migration
{
    public function up(): void
    {
        // All DATETIME columns hold UTC. Automation settings are per slam; seeded
        // values are placeholders for the organisers to confirm.
        $this->db->query("CREATE TABLE slams (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            type ENUM('regional','final') NOT NULL DEFAULT 'regional',
            slug VARCHAR(80) NOT NULL,
            schedule_version INT UNSIGNED NOT NULL DEFAULT 1,
            schedule_is_public TINYINT(1) NOT NULL DEFAULT 0,
            schedule_published_at DATETIME NULL,
            description TEXT NULL,
            venue_name VARCHAR(150) NOT NULL DEFAULT '',
            address_line VARCHAR(255) NOT NULL DEFAULT '',
            city VARCHAR(100) NOT NULL DEFAULT '',
            district VARCHAR(100) NOT NULL DEFAULT '',
            state VARCHAR(100) NOT NULL DEFAULT 'Kerala',
            pincode VARCHAR(10) NULL,
            latitude DECIMAL(9,6) NULL,
            longitude DECIMAL(9,6) NULL,
            map_url VARCHAR(500) NULL,
            contact_name VARCHAR(120) NULL,
            contact_phone VARCHAR(20) NULL,
            contact_email VARCHAR(190) NULL,
            banner_path VARCHAR(255) NULL,
            opens_at DATETIME NOT NULL,
            checkin_opens_at DATETIME NULL,
            checkin_closes_at DATETIME NULL,
            reviews_start_at DATETIME NULL,
            closes_at DATETIME NOT NULL,
            event_ends_at DATETIME NULL,
            status ENUM('draft','scheduled','open','checkin_open','checkin_closed','reviewing','reviews_complete','revision','revision_closed','closed','marks_calculated','ended') NOT NULL DEFAULT 'draft',
            phase_changed_at DATETIME NULL,
            auto_mode ENUM('auto','manual') NOT NULL DEFAULT 'auto',
            hold_next_step TINYINT(1) NOT NULL DEFAULT 0,
            default_review_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5,
            changeover_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 2,
            edit_window_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
            close_grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5,
            default_tea_break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
            default_lunch_break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 45,
            readiness_alerted_at DATETIME NULL,
            activated_at DATETIME NULL,
            ended_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_slams_slug (slug),
            KEY ix_slams_status (status),
            CONSTRAINT ck_slams_times CHECK (closes_at > opens_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->forge->dropTable('slams', true);
    }
}
