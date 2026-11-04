<?php

declare(strict_types=1);

namespace Modules\Checkin\Database\Migrations;

use CodeIgniter\Database\Migration;

/** A hash of the browser's headers and the badge, used to recognise the same browser when its cookie is gone. */
class CheckinBrowserSignature extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE checkins ADD COLUMN browser_signature CHAR(64) NULL AFTER session_token_hash');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE checkins DROP COLUMN browser_signature');
    }
}
