<?php

declare(strict_types=1);

namespace Modules\Accounts\Database\Migrations;

use CodeIgniter\Database\Migration;

/** "Am I registered?" attempts are counted per IP in login_attempts. */
class LookupAttempts extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE login_attempts MODIFY kind ENUM('reviewer','admin','volunteer','lookup') NOT NULL");
    }

    public function down(): void
    {
        $this->db->query("DELETE FROM login_attempts WHERE kind = 'lookup'");
        $this->db->query("ALTER TABLE login_attempts MODIFY kind ENUM('reviewer','admin','volunteer') NOT NULL");
    }
}
