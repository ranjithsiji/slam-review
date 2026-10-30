<?php

declare(strict_types=1);

namespace Modules\Accounts\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAdminsAndLoginAttempts extends Migration
{
    public function up(): void
    {
        $this->db->query("CREATE TABLE admins (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(60) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            name VARCHAR(120) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_admins_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE login_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            kind ENUM('reviewer','admin','volunteer') NOT NULL DEFAULT 'reviewer',
            badge_id_or_username VARCHAR(80) NOT NULL,
            success TINYINT(1) NOT NULL,
            created_at DATETIME NOT NULL,
            KEY ix_la_ip (ip, created_at),
            KEY ix_la_key (badge_id_or_username, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->forge->dropTable('login_attempts', true);
        $this->forge->dropTable('admins', true);
    }
}
