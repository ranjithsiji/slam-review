<?php

declare(strict_types=1);

namespace Modules\Core\Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Application settings. Override any value from .env as slam.<property>.
 */
class Slam extends BaseConfig
{
    /** Base URL printed in badge QR codes: {checkinUrl}/in/{BADGE_ID}. */
    public string $checkinUrl = 'http://localhost:8080';

    /** Reviewer login throttling (per submitted ID). */
    public int $idMaxFailures = 5;
    public int $idWindowMinutes = 10;
    public int $idLockoutMinutes = 15;

    /**
     * Per-IP limits. A whole venue usually shares one public IP (Wi-Fi NAT), so these
     * are deliberately higher than the per-ID limit. Tune before each event.
     */
    public int $ipMaxFailures = 60;
    public int $ipWindowMinutes = 10;
    public int $ipLockoutMinutes = 15;
    public int $ipMaxDistinctIds = 40;
    public int $ipDistinctWindowMinutes = 60;

    /** Staff (admin/volunteer) login throttling. */
    public int $staffMaxFailures = 5;
    public int $staffWindowMinutes = 10;
    public int $staffLockoutMinutes = 15;

    /** Reviewer poll interval suggested to clients (seconds). */
    public int $pollSeconds = 4;

    /** Public schedule JSON cache (seconds). */
    public int $publicCacheSeconds = 5;

    /** Import limits. */
    public int $importMaxRows = 5000;
    public int $importMaxBytes = 2 * 1024 * 1024;

    /** Upload limits. */
    public int $photoMaxBytes = 3 * 1024 * 1024;

    /** Export row cap for table CSV exports. */
    public int $exportMaxRows = 50000;
}
