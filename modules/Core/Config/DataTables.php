<?php

declare(strict_types=1);

namespace Modules\Core\Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Registry of server-side table endpoints: POST /api/tables/{name}.
 * Each definition lives in its own module's DataTables folder.
 */
class DataTables extends BaseConfig
{
    /** @var array<string, class-string<\Modules\Core\DataTables\TableDefinition>> */
    public array $tables = [
        'slams'              => \Modules\Slams\DataTables\SlamsTable::class,
        'phase-log'          => \Modules\Slams\DataTables\PhaseLogTable::class,
        'presenters'         => \Modules\Presenters\DataTables\PresentersTable::class,
        'performances'       => \Modules\Presenters\DataTables\PerformancesTable::class,
        'attendees'          => \Modules\Attendees\DataTables\AttendeesTable::class,
        'import-rows'        => \Modules\Attendees\DataTables\ImportRowsTable::class,
        'import-batches'     => \Modules\Attendees\DataTables\ImportBatchesTable::class,
        'volunteers'         => \Modules\Accounts\DataTables\VolunteersTable::class,
        'checkins'           => \Modules\Checkin\DataTables\CheckinsTable::class,
        'attendee-search'    => \Modules\Checkin\DataTables\AttendeeSearchTable::class,
        'questions'          => \Modules\Reviews\DataTables\QuestionsTable::class,
        'marks'              => \Modules\Scoring\DataTables\MarksTable::class,
        'mark-stats'         => \Modules\Scoring\DataTables\MarkStatsTable::class,
        'complete-reviewers' => \Modules\Scoring\DataTables\CompleteReviewersTable::class,
        'mark-runs'          => \Modules\Scoring\DataTables\MarkRunsTable::class,
        'activity-log'       => \Modules\ActivityLog\DataTables\ActivityLogTable::class,
        'schedule-snapshots' => \Modules\Schedule\DataTables\ScheduleSnapshotsTable::class,
    ];
}
