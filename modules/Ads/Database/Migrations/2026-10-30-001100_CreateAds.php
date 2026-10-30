<?php

declare(strict_types=1);

namespace Modules\Ads\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Text and image ads shown to the audience while a tea or lunch break is running. */
class CreateAds extends Migration
{
    public function up(): void
    {
        $this->db->query("CREATE TABLE ads (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slam_id INT UNSIGNED NOT NULL,
            kind ENUM('text','image') NOT NULL,
            sponsor VARCHAR(120) NULL,
            headline VARCHAR(160) NULL,
            body VARCHAR(600) NULL,
            image_path VARCHAR(255) NULL,
            image_thumb_path VARCHAR(255) NULL,
            link_url VARCHAR(500) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            KEY ix_ads_slam (slam_id, is_active, sort_order),
            CONSTRAINT fk_ads_slam FOREIGN KEY (slam_id) REFERENCES slams(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->forge->dropTable('ads', true);
    }
}
