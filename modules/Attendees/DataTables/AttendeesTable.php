<?php

declare(strict_types=1);

namespace Modules\Attendees\DataTables;

use Modules\Core\DataTables\TableDefinition;

class AttendeesTable extends TableDefinition
{
    /** Performances that ran in the attendee's slam (opened, not skipped). */
    private const OPENED = "(SELECT COUNT(*) FROM schedule_items si WHERE si.slam_id = a.slam_id AND si.item_type = 'performance' AND si.status IN ('open','closed')
        AND EXISTS (SELECT 1 FROM review_windows rw WHERE rw.schedule_item_id = si.id))";
    private const REVIEWS  = '(SELECT COUNT(*) FROM reviews r WHERE r.attendee_id = a.id)';
    private const IS_REP   = '(EXISTS (SELECT 1 FROM audience_representatives ar WHERE ar.slam_id = a.slam_id AND ar.attendee_id = a.id))';
    /** An audience user who reviewed every performance that ran. */
    private const COMPLETE = "(a.user_type = 'user' AND " . self::OPENED . ' > 0 AND ' . self::REVIEWS . ' >= ' . self::OPENED . ')';

    public function roles(): array
    {
        return ['admin'];
    }

    public function from(): string
    {
        return 'attendees a LEFT JOIN checkins c ON c.attendee_id = a.id AND c.checked_out_at IS NULL';
    }

    public function columns(): array
    {
        return [
            'id'                => ['sql' => 'a.id', 'order' => true],
            'name'              => ['sql' => 'a.name', 'search' => true, 'order' => true],
            'badge_id'          => ['sql' => 'a.badge_id', 'search' => true, 'order' => true],
            'user_type'         => ['sql' => 'a.user_type', 'order' => true],
            'mobile'            => ['sql' => 'a.mobile', 'search' => true],
            'email'             => ['sql' => 'a.email', 'search' => true],
            'checked_in'        => ['sql' => '(c.id IS NOT NULL)', 'order' => true],
            'badge_print_count' => ['sql' => 'a.badge_print_count', 'order' => true],
            'import_batch_id'   => ['sql' => 'a.import_batch_id', 'order' => true],
            'is_active'         => ['sql' => 'a.is_active', 'order' => true],
            'reviews'           => ['sql' => self::REVIEWS, 'order' => true],
            'opened'            => ['sql' => self::OPENED],
            'complete'          => ['sql' => self::COMPLETE, 'order' => true],
            'is_representative' => ['sql' => self::IS_REP, 'order' => true],
            'can_make_rep'      => ['sql' => '(' . self::COMPLETE . ' AND NOT ' . self::IS_REP . ' AND a.is_active = 1)'],
        ];
    }

    public function filters(): array
    {
        return [
            'slam_id'         => self::intFilter('a.slam_id'),
            'user_type'       => self::enumFilter('a.user_type', ['user', 'judge']),
            'import_batch_id' => self::intFilter('a.import_batch_id'),
            'checked_in'      => static fn ($v) => [$v === '1' || $v === 1 || $v === true ? 'c.id IS NOT NULL' : 'c.id IS NULL', []],
            'not_printed'     => static fn ($v) => [$v ? 'a.badge_print_count = 0' : '1=1', []],
            'is_active'       => self::enumFilter('a.is_active', ['0', '1', 0, 1]),
            'complete'        => static fn ($v) => [$v === '1' || $v === 1 || $v === true ? self::COMPLETE : 'NOT ' . self::COMPLETE, []],
            'representative'  => static fn ($v) => [$v === '1' || $v === 1 || $v === true ? self::IS_REP : 'NOT ' . self::IS_REP, []],
        ];
    }

    public function requiredFilters(): array
    {
        return ['slam_id'];
    }

    public function defaultOrder(): array
    {
        return ['name', 'asc'];
    }
}
