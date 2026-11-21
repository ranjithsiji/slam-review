<?php

declare(strict_types=1);

namespace Modules\Ads\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The ad pool: an ad without a slam (slam_id NULL) can be chosen for any slam's breaks (slam_ads). Existing ads keep
 * their slam and stay slam-specific. Additive only: nothing is removed.
 */
class AdPool extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE ads MODIFY slam_id INT UNSIGNED NULL');
        if (! $this->db->tableExists('slam_ads')) {
            $this->db->query("CREATE TABLE slam_ads (
                slam_id INT UNSIGNED NOT NULL,
                ad_id INT UNSIGNED NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                added_by INT UNSIGNED NULL,
                created_at DATETIME NULL,
                PRIMARY KEY (slam_id, ad_id),
                KEY ix_slam_ads_ad (ad_id),
                CONSTRAINT fk_slam_ads_slam FOREIGN KEY (slam_id) REFERENCES slams(id),
                CONSTRAINT fk_slam_ads_ad FOREIGN KEY (ad_id) REFERENCES ads(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    }

    /** For the test database only: a live database is never rolled back (see CLAUDE.md). */
    public function down(): void
    {
        $this->forge->dropTable('slam_ads', true);
    }
}
