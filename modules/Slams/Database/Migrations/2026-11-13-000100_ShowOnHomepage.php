<?php

declare(strict_types=1);

namespace Modules\Slams\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Per-slam switch: listed on the public home page or not. Existing slams stay visible (default 1). */
class ShowOnHomepage extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE slams ADD COLUMN show_on_homepage TINYINT(1) NOT NULL DEFAULT 1 AFTER schedule_is_public');
    }

    public function down(): void
    {
        // Migrations never delete data (see CLAUDE.md): the column stays.
    }
}
