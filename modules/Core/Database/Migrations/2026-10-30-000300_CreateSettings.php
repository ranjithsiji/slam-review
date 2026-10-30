<?php

declare(strict_types=1);

namespace Modules\Core\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Application settings edited by admins (display timezone, time format). */
class CreateSettings extends Migration
{
    public function up(): void
    {
        $this->db->query('CREATE TABLE app_settings (
            `key` VARCHAR(64) NOT NULL PRIMARY KEY,
            value VARCHAR(255) NOT NULL,
            updated_by INT UNSIGNED NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(): void
    {
        $this->forge->dropTable('app_settings', true);
    }
}
