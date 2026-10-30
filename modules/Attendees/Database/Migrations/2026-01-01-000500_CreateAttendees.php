<?php

declare(strict_types=1);

namespace Modules\Attendees\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAttendees extends Migration
{
    public function up(): void
    {
        $this->db->query("CREATE TABLE import_batches (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slam_id INT UNSIGNED NOT NULL,
            filename VARCHAR(255) NOT NULL,
            file_sha256 CHAR(64) NOT NULL,
            uploaded_by INT UNSIGNED NOT NULL,
            mode ENUM('skip_existing','update_existing') NOT NULL DEFAULT 'skip_existing',
            rows_total INT UNSIGNED NOT NULL DEFAULT 0,
            rows_created INT UNSIGNED NOT NULL DEFAULT 0,
            rows_updated INT UNSIGNED NOT NULL DEFAULT 0,
            rows_skipped INT UNSIGNED NOT NULL DEFAULT 0,
            rows_failed INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('previewed','committed','failed','cancelled') NOT NULL DEFAULT 'previewed',
            error_report_path VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            committed_at DATETIME NULL,
            KEY ix_ib_slam (slam_id, file_sha256),
            CONSTRAINT fk_ib_slam FOREIGN KEY (slam_id) REFERENCES slams(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE attendees (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slam_id INT UNSIGNED NOT NULL,
            badge_id CHAR(7) NOT NULL,
            name VARCHAR(120) NOT NULL,
            mobile CHAR(10) NOT NULL,
            email VARCHAR(190) NULL,
            user_type ENUM('user','judge') NOT NULL DEFAULT 'user',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            import_batch_id INT UNSIGNED NULL,
            badge_print_count INT UNSIGNED NOT NULL DEFAULT 0,
            badge_last_printed_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_att_badge (badge_id),
            UNIQUE KEY uq_att_slam_mobile (slam_id, mobile),
            KEY ix_att_name (slam_id, name),
            KEY ix_att_email (slam_id, email),
            KEY ix_att_batch (import_batch_id),
            CONSTRAINT fk_att_slam FOREIGN KEY (slam_id) REFERENCES slams(id),
            CONSTRAINT fk_att_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE import_batch_rows (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            batch_id INT UNSIGNED NOT NULL,
            line_no INT UNSIGNED NOT NULL,
            status ENUM('new','duplicate','invalid') NOT NULL,
            name VARCHAR(255) NULL,
            mobile VARCHAR(40) NULL,
            email VARCHAR(255) NULL,
            user_type VARCHAR(20) NULL,
            badge_id VARCHAR(20) NULL,
            error VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            KEY ix_ibr_batch (batch_id, status, line_no),
            CONSTRAINT fk_ibr_batch FOREIGN KEY (batch_id) REFERENCES import_batches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->forge->dropTable('import_batch_rows', true);
        $this->forge->dropTable('attendees', true);
        $this->forge->dropTable('import_batches', true);
    }
}
