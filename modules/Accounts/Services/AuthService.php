<?php

declare(strict_types=1);

namespace Modules\Accounts\Services;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/**
 * Admin and volunteer username/password login, plus their account CRUD.
 * (Reviewer "login" is a check-in, see Modules\Checkin\Services\CheckinService.)
 */
class AuthService
{
    public const GENERIC_FAILURE = 'Invalid username or password.';

    public static function hash(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    /**
     * @return array{type: string, id: int, label: string, slam_id: int|null, name: string}
     */
    public function login(string $kind, string $username, string $password, string $ip): array
    {
        if (! in_array($kind, ['admin', 'volunteer'], true)) {
            throw new DomainException('invalid_kind', self::GENERIC_FAILURE, 400);
        }
        $throttle = service('throttle');
        $anon     = Actor::anonymous();
        $wait     = $throttle->lockedFor($kind, $ip, $username);
        if ($wait > 0) {
            service('activityLog')->log($anon, 'auth.login.failure', ['outcome' => 'denied', 'reason' => 'locked_out', 'meta' => ['kind' => $kind]]);
            throw new DomainException('locked_out', 'Too many attempts. Try again in ' . (int) ceil($wait / 60) . ' minutes.', 429);
        }
        $table = $kind === 'admin' ? 'admins' : 'volunteers';
        $user  = Db::row("SELECT * FROM {$table} WHERE username = ?", [strtolower(trim($username))]);
        // Always run password_verify so timing does not reveal whether the user exists.
        $hash  = $user['password_hash'] ?? '$2y$10$usesomesillystringforsaltusesomesillystringforsaltusesom';
        $ok    = password_verify($password, $hash) && $user !== null && (int) $user['is_active'] === 1;
        if ($ok && $kind === 'volunteer') {
            $ok = Db::value('SELECT id FROM slams WHERE id = ?', [$user['slam_id']]) !== null;
        }
        if (! $ok) {
            $throttle->fail($kind, $ip, $username, $anon, 'bad_credentials');
            throw new DomainException('bad_credentials', self::GENERIC_FAILURE, 401);
        }
        if (password_needs_rehash($user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            db_connect()->table($table)->where('id', $user['id'])->update(['password_hash' => self::hash($password)]);
        }
        $throttle->record($kind, $ip, $username, true);

        $auth = [
            'type'    => $kind,
            'id'      => (int) $user['id'],
            'label'   => $user['username'],
            'name'    => $user['name'],
            'slam_id' => $kind === 'volunteer' ? (int) $user['slam_id'] : null,
        ];
        service('activityLog')->log(new Actor($kind, $auth['id'], $auth['label'], $auth['slam_id']), 'auth.login.success', ['slam_id' => $auth['slam_id']]);

        return $auth;
    }

    /** @param array<string, mixed> $input */
    public function saveVolunteer(?int $id, array $input, Actor $actor): int
    {
        $errors   = [];
        $username = strtolower(trim((string) ($input['username'] ?? '')));
        $name     = trim((string) ($input['name'] ?? ''));
        $slamId   = (int) ($input['slam_id'] ?? 0);
        $password = (string) ($input['password'] ?? '');
        if (! preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
            $errors['username'] = 'Username: 3–60 lowercase letters, digits, dot, dash or underscore.';
        } elseif (Db::value('SELECT id FROM volunteers WHERE username = ? AND id <> ?', [$username, $id ?? 0]) !== null) {
            $errors['username'] = 'Username already taken.';
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Name must be 2–120 characters.';
        }
        if (Db::value('SELECT id FROM slams WHERE id = ?', [$slamId]) === null) {
            $errors['slam_id'] = 'Choose a slam.';
        }
        if (($id === null || $password !== '') && mb_strlen($password) < 10) {
            $errors['password'] = 'Password must be at least 10 characters.';
        }
        if ($errors !== []) {
            throw new DomainException('validation', 'Please correct the highlighted fields.', 422, $errors);
        }
        $data = ['username' => $username, 'name' => $name, 'slam_id' => $slamId, 'is_active' => ! empty($input['is_active']) ? 1 : 0, 'updated_at' => Clock::db()];
        if ($password !== '') {
            $data['password_hash'] = self::hash($password);
        }

        return Db::tx(function ($db) use ($id, $data, $actor): int {
            if ($id === null) {
                $db->table('volunteers')->insert($data + ['created_at' => Clock::db()]);
                $id = (int) $db->insertID();
                service('activityLog')->log($actor, 'volunteer.created', ['slam_id' => $data['slam_id'], 'target_type' => 'volunteer', 'target_id' => $id, 'new' => $data]);
            } else {
                $old = Db::row('SELECT * FROM volunteers WHERE id = ?', [$id]) ?? throw new DomainException('not_found', 'Volunteer not found.', 404);
                $db->table('volunteers')->where('id', $id)->update($data);
                [$o, $n] = \Modules\ActivityLog\Services\ActivityLogService::diff($old, $data);
                $action  = ((int) $old['is_active'] === 1 && $data['is_active'] === 0) ? 'volunteer.deactivated' : 'volunteer.updated';
                service('activityLog')->log($actor, $action, ['slam_id' => $data['slam_id'], 'target_type' => 'volunteer', 'target_id' => $id, 'old' => $o, 'new' => $n]);
            }

            return $id;
        });
    }

    /** Create an admin (CLI command / admin screen). */
    public function createAdmin(string $username, string $name, string $password, Actor $actor): int
    {
        $username = strtolower(trim($username));
        if (! preg_match('/^[a-z0-9._-]{3,60}$/', $username) || mb_strlen($password) < 10) {
            throw new DomainException('validation', 'Username must be 3–60 simple characters and password at least 10 characters.');
        }
        if (Db::value('SELECT id FROM admins WHERE username = ?', [$username]) !== null) {
            throw new DomainException('validation', 'Username already taken.');
        }
        $db = db_connect();
        $db->table('admins')->insert([
            'username' => $username, 'name' => $name, 'password_hash' => self::hash($password), 'is_active' => 1,
            'created_at' => Clock::db(), 'updated_at' => Clock::db(),
        ]);
        $id = (int) $db->insertID();
        service('activityLog')->log($actor, 'admin.created', ['target_type' => 'admin', 'target_id' => $id, 'new' => ['username' => $username, 'name' => $name]]);

        return $id;
    }
}
