<?php

declare(strict_types=1);

namespace Modules\Accounts\Database\Migrations;

use CodeIgniter\Database\Migration;

/** A slam operator is an admins row with role = 'operator' bound to one slam. */
class OperatorRole extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE admins
            ADD COLUMN role ENUM('admin','operator') NOT NULL DEFAULT 'admin' AFTER name,
            ADD COLUMN slam_id INT UNSIGNED NULL AFTER role,
            ADD KEY ix_admins_slam (slam_id),
            ADD CONSTRAINT fk_admins_slam FOREIGN KEY (slam_id) REFERENCES slams(id)");
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE admins DROP FOREIGN KEY fk_admins_slam, DROP KEY ix_admins_slam, DROP COLUMN slam_id, DROP COLUMN role');
    }
}
