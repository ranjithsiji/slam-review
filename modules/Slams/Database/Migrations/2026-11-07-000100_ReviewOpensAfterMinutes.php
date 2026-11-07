<?php

declare(strict_types=1);

namespace Modules\Slams\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Automatic mode: the minute of the presentation at which its review opens. NULL = half the presentation (as before). */
class ReviewOpensAfterMinutes extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE slams ADD COLUMN review_opens_after_minutes INT UNSIGNED NULL AFTER presentation_minutes');
    }

    public function down(): void
    {
        // Migrations never delete data (see CLAUDE.md): the column stays.
    }
}
