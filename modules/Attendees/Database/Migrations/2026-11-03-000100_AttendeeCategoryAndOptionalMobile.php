<?php

declare(strict_types=1);

namespace Modules\Attendees\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Attendee category (used to balance the audience representatives). A person
 * whose number is unusable can be registered without one; one mobile number is
 * still one person per slam.
 */
class AttendeeCategoryAndOptionalMobile extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE attendees
            ADD COLUMN category VARCHAR(20) NULL AFTER user_type,
            MODIFY mobile CHAR(10) NULL');
        $this->db->query('ALTER TABLE import_batch_rows ADD COLUMN category VARCHAR(20) NULL AFTER user_type');
    }

    public function down(): void
    {
        // Mobile stays optional: making it required again could fail on people without a number.
        $this->db->query('ALTER TABLE import_batch_rows DROP COLUMN category');
        $this->db->query('ALTER TABLE attendees DROP COLUMN category');
    }
}
