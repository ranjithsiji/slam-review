<?php

declare(strict_types=1);

namespace Modules\Scoring\DataTables;

use Modules\Core\DataTables\TableDefinition;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\DomainException;

/**
 * Audience users who reviewed every opened performance (the pool for audience
 * representatives; also usable for prizes or a raffle). Filter 'panel' narrows
 * the list to the representatives ('selected') or everyone else ('others').
 */
class CompleteReviewersTable extends TableDefinition
{
    public function roles(): array
    {
        return ['admin'];
    }

    public function prepare(Actor $actor, array $filters): void
    {
        $slamId = filter_var($filters['slam_id'] ?? null, FILTER_VALIDATE_INT);
        if ($slamId === false) {
            throw new DomainException('filter_required', "Filter 'slam_id' is required.", 422);
        }
        $db = db_connect();
        $db->query('DROP TEMPORARY TABLE IF EXISTS tmp_complete');
        $db->query('CREATE TEMPORARY TABLE tmp_complete (attendee_id INT UNSIGNED PRIMARY KEY) ENGINE=MEMORY');
        foreach (service('scoring')->completeReviewerIds($slamId) as $id) {
            $db->table('tmp_complete')->insert(['attendee_id' => $id]);
        }
    }

    public function from(): string
    {
        return 'tmp_complete t JOIN attendees a ON a.id = t.attendee_id
            LEFT JOIN audience_representatives ar ON ar.slam_id = a.slam_id AND ar.attendee_id = a.id';
    }

    public function columns(): array
    {
        return [
            'id'       => ['sql' => 'a.id', 'order' => true],
            'name'     => ['sql' => 'a.name', 'search' => true, 'order' => true],
            'badge_id' => ['sql' => 'a.badge_id', 'search' => true, 'order' => true],
            'mobile'   => ['sql' => 'a.mobile', 'search' => true],
            'email'    => ['sql' => 'a.email'],
            'reviews'  => ['sql' => '(SELECT COUNT(*) FROM reviews r WHERE r.attendee_id = a.id)', 'order' => true],
            'is_representative' => ['sql' => '(ar.id IS NOT NULL)', 'order' => true],
            'rep_source'        => ['sql' => 'ar.source'],
        ];
    }

    public function filters(): array
    {
        return [
            'slam_id' => static fn ($v) => ['1=1', []],
            'panel'   => static fn ($v) => match ($v) {
                'selected' => ['ar.id IS NOT NULL', []],
                'others'   => ['ar.id IS NULL', []],
                'all'      => ['1=1', []],
                default    => throw new DomainException('invalid_filter', 'Invalid filter value.', 422),
            },
        ];
    }

    public function defaultOrder(): array
    {
        return ['name', 'asc'];
    }
}
