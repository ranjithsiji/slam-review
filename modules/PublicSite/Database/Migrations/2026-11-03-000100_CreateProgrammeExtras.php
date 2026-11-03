<?php

declare(strict_types=1);

namespace Modules\PublicSite\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Display-only parts of the public schedule (registration, opening talks, the closing session). They are kept out of
 * schedule_items on purpose: slots drive the live timeline, must start after Gate Close, and hold back Reviews Complete.
 */
class CreateProgrammeExtras extends Migration
{
    public function up(): void
    {
        $this->db->query("CREATE TABLE programme_extras (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slam_id INT UNSIGNED NOT NULL,
            placement ENUM('before','after') NOT NULL,
            position INT NOT NULL,
            item_type ENUM('heading','item','break') NOT NULL,
            break_type ENUM('tea','lunch','other') NULL,
            label VARCHAR(200) NOT NULL,
            note VARCHAR(255) NULL,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            KEY ix_pe_slam (slam_id, placement, position),
            CONSTRAINT fk_pe_slam FOREIGN KEY (slam_id) REFERENCES slams(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->forge->dropTable('programme_extras');
    }
}
