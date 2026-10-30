<?php

declare(strict_types=1);

namespace Modules\Accounts\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateVolunteers extends Migration
{
    public function up(): void
    {
        $this->db->query("CREATE TABLE volunteers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slam_id INT UNSIGNED NOT NULL,
            username VARCHAR(60) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            name VARCHAR(120) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_volunteers_username (username),
            KEY ix_volunteers_slam (slam_id),
            CONSTRAINT fk_volunteers_slam FOREIGN KEY (slam_id) REFERENCES slams(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->forge->dropTable('volunteers', true);
    }
}
