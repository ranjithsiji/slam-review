<?php

declare(strict_types=1);

namespace Modules\Slams\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Core\Libraries\Actor;

/**
 * Cron backup for the timeline (every minute):
 *   * * * * * cd /path/to/app && php spark slam:tick >> writable/logs/cron.log 2>&1
 * Correctness never depends on it: every request also checks the server clock.
 */
class Tick extends BaseCommand
{
    protected $group       = 'Science Slam';
    protected $name        = 'slam:tick';
    protected $description = 'Apply due timeline steps for every live slam and run housekeeping.';

    public function run(array $params): void
    {
        $applied = service('slamPhase')->tickAll();
        $purged  = service('importer')->purgeOldPreviews();
        service('activityLog')->log(Actor::system(), 'system.cron_run', ['meta' => ['applied' => $applied, 'import_rows_purged' => $purged]]);
        foreach ($applied as $slamId => $steps) {
            CLI::write("slam {$slamId}: " . implode(', ', $steps));
        }
    }
}
