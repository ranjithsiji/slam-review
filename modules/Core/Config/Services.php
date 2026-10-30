<?php

declare(strict_types=1);

namespace Modules\Core\Config;

use CodeIgniter\Config\BaseService;
use Modules\Accounts\Services\AuthService;
use Modules\Accounts\Services\ThrottleService;
use Modules\ActivityLog\Services\ActivityLogService;
use Modules\Attendees\Services\AttendeeService;
use Modules\Attendees\Services\BadgeIdService;
use Modules\Attendees\Services\BadgePdfService;
use Modules\Attendees\Services\ImportService;
use Modules\Attendees\Services\QrService;
use Modules\Checkin\Services\CheckinService;
use Modules\Core\DataTables\DataTableService;
use Modules\Core\Libraries\PublicCache;
use Modules\Core\Services\SettingsService;
use Modules\Presenters\Services\PhotoService;
use Modules\Presenters\Services\PresenterService;
use Modules\Reviews\Services\LiveStateService;
use Modules\Reviews\Services\QuestionService;
use Modules\Reviews\Services\ReviewService;
use Modules\Schedule\Services\ScheduleService;
use Modules\Scoring\Services\JudgeMarkService;
use Modules\Scoring\Services\MarkRunService;
use Modules\Scoring\Services\RepresentativeService;
use Modules\Scoring\Services\ScoringService;
use Modules\Slams\Services\SlamPhaseService;
use Modules\Slams\Services\SlamService;

/**
 * Service locator for all module services: service('slamPhase'), etc.
 * Shared instances per request; tests may call Services::reset().
 */
class Services extends BaseService
{
    public static function activityLog(bool $getShared = true): ActivityLogService
    {
        return $getShared ? static::getSharedInstance('activityLog') : new ActivityLogService();
    }

    public static function publicCache(bool $getShared = true): PublicCache
    {
        return $getShared ? static::getSharedInstance('publicCache') : new PublicCache();
    }

    public static function throttle(bool $getShared = true): ThrottleService
    {
        return $getShared ? static::getSharedInstance('throttle') : new ThrottleService();
    }

    public static function auth(bool $getShared = true): AuthService
    {
        return $getShared ? static::getSharedInstance('auth') : new AuthService();
    }

    public static function slams(bool $getShared = true): SlamService
    {
        return $getShared ? static::getSharedInstance('slams') : new SlamService();
    }

    public static function slamPhase(bool $getShared = true): SlamPhaseService
    {
        return $getShared ? static::getSharedInstance('slamPhase') : new SlamPhaseService();
    }

    public static function schedule(bool $getShared = true): ScheduleService
    {
        return $getShared ? static::getSharedInstance('schedule') : new ScheduleService();
    }

    public static function presenters(bool $getShared = true): PresenterService
    {
        return $getShared ? static::getSharedInstance('presenters') : new PresenterService();
    }

    public static function photos(bool $getShared = true): PhotoService
    {
        return $getShared ? static::getSharedInstance('photos') : new PhotoService();
    }

    public static function badgeIds(bool $getShared = true): BadgeIdService
    {
        return $getShared ? static::getSharedInstance('badgeIds') : new BadgeIdService();
    }

    public static function attendees(bool $getShared = true): AttendeeService
    {
        return $getShared ? static::getSharedInstance('attendees') : new AttendeeService();
    }

    public static function importer(bool $getShared = true): ImportService
    {
        return $getShared ? static::getSharedInstance('importer') : new ImportService();
    }

    public static function qr(bool $getShared = true): QrService
    {
        return $getShared ? static::getSharedInstance('qr') : new QrService();
    }

    public static function badgePdf(bool $getShared = true): BadgePdfService
    {
        return $getShared ? static::getSharedInstance('badgePdf') : new BadgePdfService();
    }

    public static function checkins(bool $getShared = true): CheckinService
    {
        return $getShared ? static::getSharedInstance('checkins') : new CheckinService();
    }

    public static function questions(bool $getShared = true): QuestionService
    {
        return $getShared ? static::getSharedInstance('questions') : new QuestionService();
    }

    public static function reviews(bool $getShared = true): ReviewService
    {
        return $getShared ? static::getSharedInstance('reviews') : new ReviewService();
    }

    public static function liveState(bool $getShared = true): LiveStateService
    {
        return $getShared ? static::getSharedInstance('liveState') : new LiveStateService();
    }

    public static function scoring(bool $getShared = true): ScoringService
    {
        return $getShared ? static::getSharedInstance('scoring') : new ScoringService();
    }

    public static function markRuns(bool $getShared = true): MarkRunService
    {
        return $getShared ? static::getSharedInstance('markRuns') : new MarkRunService();
    }

    public static function judgeMarks(bool $getShared = true): JudgeMarkService
    {
        return $getShared ? static::getSharedInstance('judgeMarks') : new JudgeMarkService();
    }

    public static function representatives(bool $getShared = true): RepresentativeService
    {
        return $getShared ? static::getSharedInstance('representatives') : new RepresentativeService();
    }

    public static function appSettings(bool $getShared = true): SettingsService
    {
        return $getShared ? static::getSharedInstance('appSettings') : new SettingsService();
    }

    public static function dataTables(bool $getShared = true): DataTableService
    {
        return $getShared ? static::getSharedInstance('dataTables') : new DataTableService();
    }
}
