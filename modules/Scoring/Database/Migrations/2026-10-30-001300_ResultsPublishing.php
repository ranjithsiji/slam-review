<?php

declare(strict_types=1);

namespace Modules\Scoring\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Results are shown on the public home page only after the organisers publish them. */
class ResultsPublishing extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE slams ADD COLUMN results_is_public TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN results_published_at DATETIME NULL');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE slams DROP COLUMN results_is_public, DROP COLUMN results_published_at');
    }
}
