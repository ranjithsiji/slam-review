<?php

declare(strict_types=1);

namespace Modules\Slams\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Automatic mode: a performance starts on schedule, its review opens at half of presentation_minutes
 * and the presentation ends at presentation_minutes. 0 = the old behaviour (review opens when the performance starts).
 */
class PresentationMinutes extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE slams ADD COLUMN presentation_minutes INT UNSIGNED NOT NULL DEFAULT 10 AFTER default_review_minutes');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE slams DROP COLUMN presentation_minutes');
    }
}
