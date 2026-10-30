<?php

declare(strict_types=1);

namespace Modules\Scoring\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Three scoring groups: general reviewers (audience users who are not
 * representatives), audience representatives (drawn at random from the
 * audience users who reviewed every performance, then edited by the admin)
 * and judges.
 */
class AudienceRepresentatives extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE score_config DROP CONSTRAINT ck_sc_sum');
        $this->db->query('ALTER TABLE score_config RENAME COLUMN w_user TO w_general, RENAME COLUMN w_complete_reviewer TO w_representative');
        $this->db->query('ALTER TABLE score_config ADD COLUMN representative_count INT UNSIGNED NOT NULL DEFAULT 10 AFTER w_representative,
            ADD CONSTRAINT ck_sc_sum CHECK (w_general + w_judge + w_representative = 100)');

        $this->db->query('ALTER TABLE mark_results RENAME COLUMN avg_user TO avg_general, RENAME COLUMN n_user TO n_general,
            RENAME COLUMN avg_complete TO avg_representative, RENAME COLUMN n_complete TO n_representative');
        $this->db->query('ALTER TABLE mark_runs ADD COLUMN representatives_snapshot LONGTEXT NULL AFTER counts_snapshot');

        $this->db->query("CREATE TABLE audience_representatives (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slam_id INT UNSIGNED NOT NULL,
            attendee_id INT UNSIGNED NOT NULL,
            source ENUM('random','manual') NOT NULL,
            draw_seed INT UNSIGNED NULL,
            added_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_rep (slam_id, attendee_id),
            CONSTRAINT fk_rep_slam FOREIGN KEY (slam_id) REFERENCES slams(id),
            CONSTRAINT fk_rep_att FOREIGN KEY (attendee_id) REFERENCES attendees(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->forge->dropTable('audience_representatives', true);
        $this->db->query('ALTER TABLE mark_runs DROP COLUMN representatives_snapshot');
        $this->db->query('ALTER TABLE mark_results RENAME COLUMN avg_general TO avg_user, RENAME COLUMN n_general TO n_user,
            RENAME COLUMN avg_representative TO avg_complete, RENAME COLUMN n_representative TO n_complete');
        $this->db->query('ALTER TABLE score_config DROP CONSTRAINT ck_sc_sum, DROP COLUMN representative_count');
        $this->db->query('ALTER TABLE score_config RENAME COLUMN w_general TO w_user, RENAME COLUMN w_representative TO w_complete_reviewer');
        $this->db->query('ALTER TABLE score_config ADD CONSTRAINT ck_sc_sum CHECK (w_user + w_judge + w_complete_reviewer = 100)');
    }
}
