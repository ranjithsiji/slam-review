<?php

declare(strict_types=1);

namespace Modules\Schedule\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A performance happens on stage before and while it is reviewed: the presenter
 * walks on (performing_started_at), the review window can open while they are
 * still presenting, the performance ends (performing_ended_at) and the review
 * carries on for a while after that.
 */
class PerformanceStage extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE schedule_items ADD COLUMN performing_started_at DATETIME NULL AFTER ends_at,
            ADD COLUMN performing_ended_at DATETIME NULL AFTER performing_started_at');
        // Performances that already ran: the performance lasted as long as its review window.
        $this->db->query("UPDATE schedule_items si
            JOIN review_windows rw ON rw.id = (SELECT MAX(w.id) FROM review_windows w WHERE w.schedule_item_id = si.id)
            SET si.performing_started_at = rw.opened_at, si.performing_ended_at = rw.closed_at");
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE schedule_items DROP COLUMN performing_ended_at, DROP COLUMN performing_started_at');
    }
}
