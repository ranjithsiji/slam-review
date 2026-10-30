<?php

declare(strict_types=1);

namespace Modules\ActivityLog\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Append-only. In production, grant the application DB user only
 * INSERT, SELECT on this table (see README, "Database privileges").
 */
class CreateActivityLog extends Migration
{
    public function up(): void
    {
        $this->db->query("CREATE TABLE activity_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            occurred_at DATETIME(3) NOT NULL,
            slam_id INT UNSIGNED NULL,
            actor_type ENUM('admin','volunteer','user','judge','system','anonymous') NOT NULL,
            actor_id INT UNSIGNED NULL,
            actor_label VARCHAR(150) NULL,
            action VARCHAR(80) NOT NULL,
            target_type VARCHAR(40) NULL,
            target_id INT UNSIGNED NULL,
            outcome ENUM('success','failure','denied') NOT NULL DEFAULT 'success',
            reason VARCHAR(255) NULL,
            old_values LONGTEXT NULL,
            new_values LONGTEXT NULL,
            meta LONGTEXT NULL,
            ip VARBINARY(16) NULL,
            user_agent VARCHAR(255) NULL,
            session_ref CHAR(16) NULL,
            KEY ix_al_slam (slam_id, occurred_at),
            KEY ix_al_actor (actor_type, actor_id, occurred_at),
            KEY ix_al_action (action, occurred_at),
            KEY ix_al_target (target_type, target_id),
            KEY ix_al_ip (ip, occurred_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->forge->dropTable('activity_log', true);
    }
}
