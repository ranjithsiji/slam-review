<?php

declare(strict_types=1);

namespace Modules\Accounts\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Db;

/** php spark slam:reset-password <admin|volunteer> <username> [new-password]  (random password when omitted) */
class ResetPassword extends BaseCommand
{
    protected $group       = 'Science Slam';
    protected $name        = 'slam:reset-password';
    protected $description = 'Reset an admin, slam operator or volunteer password; prints a random one unless given.';
    protected $usage       = 'slam:reset-password <admin|volunteer> <username> [new-password]';

    public function run(array $params): void
    {
        $kind = $params[0] ?? '';
        $user = strtolower(trim($params[1] ?? ''));
        if (! in_array($kind, ['admin', 'volunteer'], true) || $user === '') {
            CLI::error('Usage: ' . $this->usage);

            return;
        }
        $id = Db::value('SELECT id FROM ' . ($kind === 'admin' ? 'admins' : 'volunteers') . ' WHERE username = ?', [$user]);
        if ($id === null) {
            CLI::error("No {$kind} named {$user}.");

            return;
        }
        try {
            $password = service('auth')->resetPassword($kind, (int) $id, $params[2] ?? null, Actor::system());
            CLI::write("{$kind} {$user}: {$password}", 'green');
        } catch (\Throwable $e) {
            CLI::error($e->getMessage());
        }
    }
}
