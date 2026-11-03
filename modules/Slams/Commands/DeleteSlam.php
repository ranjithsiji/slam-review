<?php

declare(strict_types=1);

namespace Modules\Slams\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Core\Libraries\Actor;

class DeleteSlam extends BaseCommand
{
    protected $group       = 'Science Slam';
    protected $name        = 'slam:delete';
    protected $description = 'Permanently delete a slam that has not opened yet.';
    protected $usage       = 'slam:delete <slug>';

    public function run(array $params): void
    {
        $slam = service('slams')->findBySlug((string) ($params[0] ?? ''));
        if ($slam === null) {
            CLI::error('No slam with that slug.');

            return;
        }
        try {
            service('slams')->delete((int) $slam['id'], Actor::system());
            CLI::write("Deleted: {$slam['name']}", 'green');
        } catch (\Throwable $e) {
            CLI::error($e->getMessage());
        }
    }
}
