<?php

declare(strict_types=1);

namespace Modules\Accounts\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Contact details, last sign-in and a password-changed stamp (ends other sessions on reset) for staff accounts. */
class UserManagement extends Migration
{
    public function up(): void
    {
        foreach (['admins', 'volunteers'] as $t) {
            $this->db->query("ALTER TABLE {$t}
                ADD COLUMN email VARCHAR(160) NULL AFTER name,
                ADD COLUMN phone VARCHAR(20) NULL AFTER email,
                ADD COLUMN last_login_at DATETIME NULL,
                ADD COLUMN password_changed_at DATETIME(6) NULL");
        }
    }

    public function down(): void
    {
        foreach (['admins', 'volunteers'] as $t) {
            $this->db->query("ALTER TABLE {$t} DROP COLUMN email, DROP COLUMN phone, DROP COLUMN last_login_at, DROP COLUMN password_changed_at");
        }
    }
}
