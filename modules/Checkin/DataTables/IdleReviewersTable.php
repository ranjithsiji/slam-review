<?php

declare(strict_types=1);

namespace Modules\Checkin\DataTables;

use Modules\Checkin\Services\EngagementService;
use Modules\Core\DataTables\TableDefinition;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\DomainException;

/** Checked-in reviewers who have skipped the last N finished reviews in a row (see EngagementService). */
class IdleReviewersTable extends TableDefinition
{
    public function roles(): array
    {
        return ['admin', 'volunteer'];
    }

    public function prepare(Actor $actor, array $filters): void
    {
        $slamId = filter_var($filters['slam_id'] ?? null, FILTER_VALIDATE_INT);
        if ($slamId === false) {
            throw new DomainException('filter_required', "Filter 'slam_id' is required.", 422);
        }
        $min = filter_var($filters['min'] ?? EngagementService::DEFAULT_MIN_MISSED, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
        $db  = db_connect();
        $db->query('DROP TEMPORARY TABLE IF EXISTS tmp_idle');
        $db->query('CREATE TEMPORARY TABLE tmp_idle (attendee_id INT UNSIGNED PRIMARY KEY, eligible INT NOT NULL, reviewed INT NOT NULL, missed INT NOT NULL, last_review_at DATETIME NULL, checked_in_at DATETIME NOT NULL) ENGINE=MEMORY');
        if ($actor->isVolunteer() && (int) $actor->slamId !== $slamId) {
            return;
        }
        foreach (service('engagement')->idle($slamId, $min === false ? EngagementService::DEFAULT_MIN_MISSED : $min) as $p) {
            $db->table('tmp_idle')->insert(array_intersect_key($p, array_flip(['attendee_id', 'eligible', 'reviewed', 'missed', 'last_review_at', 'checked_in_at'])));
        }
    }

    public function from(): string
    {
        return 'tmp_idle t JOIN attendees a ON a.id = t.attendee_id';
    }

    public function columns(): array
    {
        return [
            'id'             => ['sql' => 'a.id', 'order' => true],
            'attendee_id'    => ['sql' => 'a.id'],
            'name'           => ['sql' => 'a.name', 'search' => true, 'order' => true],
            'badge_id'       => ['sql' => 'a.badge_id', 'search' => true, 'order' => true],
            'mobile'         => ['sql' => 'a.mobile', 'search' => true],
            'user_type'      => ['sql' => 'a.user_type', 'order' => true],
            'checked_in_at'  => ['sql' => 't.checked_in_at', 'order' => true],
            'eligible'       => ['sql' => 't.eligible', 'order' => true],
            'reviewed'       => ['sql' => 't.reviewed', 'order' => true],
            'missed'         => ['sql' => 't.missed', 'order' => true],
            'last_review_at' => ['sql' => 't.last_review_at', 'order' => true],
        ];
    }

    public function scope(Actor $actor, array $filters): array
    {
        return self::slamScope($actor, 'a.slam_id');
    }

    public function filters(): array
    {
        return ['slam_id' => static fn ($v) => ['1=1', []], 'min' => static fn ($v) => ['1=1', []]];
    }

    public function requiredFilters(): array
    {
        return ['slam_id'];
    }

    public function defaultOrder(): array
    {
        return ['missed', 'desc'];
    }

    public function transform(array $row): array
    {
        return self::iso($row, 'checked_in_at', 'last_review_at');
    }
}
