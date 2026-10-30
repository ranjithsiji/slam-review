<?php

declare(strict_types=1);

namespace Modules\Presenters\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePresentersAndPerformances extends Migration
{
    public function up(): void
    {
        $this->db->query("CREATE TABLE presenters (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            designation_or_institution VARCHAR(200) NULL,
            profile TEXT NULL,
            photo_path VARCHAR(255) NULL,
            photo_thumb_path VARCHAR(255) NULL,
            photo_alt VARCHAR(200) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            KEY ix_presenters_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE performances (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slam_id INT UNSIGNED NOT NULL,
            presenter_id INT UNSIGNED NOT NULL,
            topic VARCHAR(200) NOT NULL,
            abstract TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_perf_slam_presenter (slam_id, presenter_id),
            CONSTRAINT fk_perf_slam FOREIGN KEY (slam_id) REFERENCES slams(id),
            CONSTRAINT fk_perf_presenter FOREIGN KEY (presenter_id) REFERENCES presenters(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->forge->dropTable('performances', true);
        $this->forge->dropTable('presenters', true);
    }
}
