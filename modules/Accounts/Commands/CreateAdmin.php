<?php

declare(strict_types=1);

namespace Modules\Accounts\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Core\Libraries\Actor;

/** php spark slam:create-admin <username> "<name>" */
class CreateAdmin extends BaseCommand
{
    protected $group       = 'Science Slam';
    protected $name        = 'slam:create-admin';
    protected $description = 'Create an admin account (prompts for the password).';
    protected $usage       = 'slam:create-admin <username> "<full name>"';

    public function run(array $params): void
    {
        $username = $params[0] ?? CLI::prompt('Username');
        $name     = $params[1] ?? CLI::prompt('Full name');
        $password = CLI::prompt('Password (min 10 characters)');
        try {
            $id = service('auth')->createAdmin($username, $name, $password, Actor::system());
            CLI::write("Admin #{$id} created.", 'green');
        } catch (\Throwable $e) {
            CLI::error($e->getMessage());
        }
    }
}
