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
        if ($ok && ($kind === 'volunteer' || ($user['role'] ?? 'admin') === 'operator')) {
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
        db_connect()->table($table)->where('id', $user['id'])->update(['last_login_at' => Clock::db()]);

        $auth = [
            'pwd'     => (string) ($user['password_changed_at'] ?? ''),
            'type'    => $kind,
            'id'      => (int) $user['id'],
            'label'   => $user['username'],
            'name'    => $user['name'],
            'slam_id' => $kind === 'volunteer' || ($user['role'] ?? 'admin') === 'operator' ? (int) $user['slam_id'] : null,
            'role'    => $kind === 'admin' ? ($user['role'] ?? 'admin') : null,
        ];
        service('activityLog')->log(new Actor($kind, $auth['id'], $auth['label'], $auth['slam_id'], $auth['role']), 'auth.login.success', ['slam_id' => $auth['slam_id']]);

        return $auth;
    }

    /** @param array<string, mixed> $input */
    public function saveVolunteer(?int $id, array $input, Actor $actor): int
    {
        $username = strtolower(trim((string) ($input['username'] ?? '')));
        $name     = trim((string) ($input['name'] ?? ''));
        $slamId   = (int) ($input['slam_id'] ?? 0);
        $password = (string) ($input['password'] ?? '');
        $errors   = $this->checkIdentity('volunteers', $id, $username, $name, $input, $contact);

        if (Db::value('SELECT id FROM slams WHERE id = ?', [$slamId]) === null) {
            $errors['slam_id'] = 'Choose a slam.';
        }
        if (($id === null || $password !== '') && mb_strlen($password) < 10) {
            $errors['password'] = 'Password must be at least 10 characters.';
        }
        if ($errors !== []) {
            throw new DomainException('validation', 'Please correct the highlighted fields.', 422, $errors);
        }
        $data = ['username' => $username, 'name' => $name, 'slam_id' => $slamId, 'is_active' => ! empty($input['is_active']) ? 1 : 0, 'updated_at' => Clock::db()] + $contact;
        if ($password !== '') {
            $data['password_hash']       = self::hash($password);
            $data['password_changed_at'] = self::stamp();
        }

        return Db::tx(function ($db) use ($id, $data, $actor): int {
            if ($id === null) {
                $db->table('volunteers')->insert($data + ['created_at' => Clock::db()]);
                $id = (int) $db->insertID();
                service('activityLog')->log($actor, 'volunteer.created', ['slam_id' => $data['slam_id'], 'target_type' => 'volunteer', 'target_id' => $id, 'new' => self::safe($data)]);
            } else {
                $old = Db::row('SELECT * FROM volunteers WHERE id = ?', [$id]) ?? throw new DomainException('not_found', 'Volunteer not found.', 404);
                $db->table('volunteers')->where('id', $id)->update($data);
                [$o, $n] = \Modules\ActivityLog\Services\ActivityLogService::diff(self::safe($old), self::safe($data));
                $action  = ((int) $old['is_active'] === 1 && $data['is_active'] === 0) ? 'volunteer.deactivated' : 'volunteer.updated';
                service('activityLog')->log($actor, $action, ['slam_id' => $data['slam_id'], 'target_type' => 'volunteer', 'target_id' => $id, 'old' => $o, 'new' => $n]);
            }

            return $id;
        });
    }

    /** Microsecond UTC stamp: two changes in the same second still end older sessions. Not the frozen test clock on purpose. */
    private static function stamp(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    /** Never write hashes or session markers to the activity log. */
    private static function safe(array $row): array
    {
        unset($row['password_hash'], $row['password_changed_at'], $row['last_login_at']);

        return $row;
    }

    /** Random password the admin hands over once: no look-alike characters. */
    public static function generatePassword(int $length = 12): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out   = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $out;
    }

    /**
     * Shared checks for username, name and the optional contact fields.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $contact filled with the cleaned email/phone
     * @return array<string, string>
     */
    private function checkIdentity(string $table, ?int $id, string $username, string $name, array $input, ?array &$contact): array
    {
        $errors = [];
        if (! preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
            $errors['username'] = 'Username: 3–60 lowercase letters, digits, dot, dash or underscore.';
        } elseif (Db::value("SELECT id FROM {$table} WHERE username = ? AND id <> ?", [$username, $id ?? 0]) !== null) {
            $errors['username'] = 'Username already taken.';
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Name must be 2–120 characters.';
        }
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if ($email !== '' && (mb_strlen($email) > 160 || ! filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $errors['email'] = 'Enter a valid email address.';
        }
        $phone = preg_replace('/[\s-]/', '', trim((string) ($input['phone'] ?? ''))) ?? '';
        if ($phone !== '' && ! preg_match('/^\+?\d{7,15}$/', $phone)) {
            $errors['phone'] = 'Enter a phone number with 7–15 digits.';
        }
        $contact = ['email' => $email !== '' ? $email : null, 'phone' => $phone !== '' ? $phone : null];

        return $errors;
    }

    /** @param array<string, mixed> $input */
    public function saveAdmin(?int $id, array $input, Actor $actor): int
    {
        $username = strtolower(trim((string) ($input['username'] ?? '')));
        $name     = trim((string) ($input['name'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $errors   = $this->checkIdentity('admins', $id, $username, $name, $input, $contact);
        if ($id === null && mb_strlen($password) < 10) {
            $errors['password'] = 'Password must be at least 10 characters.';
        }
        $active = ! empty($input['is_active']) ? 1 : 0;
        $role   = ($input['role'] ?? 'admin') === 'operator' ? 'operator' : 'admin';
        $slamId = $role === 'operator' ? (int) ($input['slam_id'] ?? 0) : null;
        if ($role === 'operator' && Db::value('SELECT id FROM slams WHERE id = ?', [$slamId]) === null) {
            $errors['slam_id'] = 'Choose the slam this operator runs.';
        }
        // Losing an administrator (deactivated, or turned into an operator) must never leave none.
        $before = $id !== null ? Db::row('SELECT role, is_active FROM admins WHERE id = ?', [$id]) : null;
        if ($before !== null && $before['role'] === 'admin' && (int) $before['is_active'] === 1 && ($active === 0 || $role !== 'admin')) {
            $this->assertCanDisable($id, $actor, $active === 0 ? 'is_active' : 'role');
        }
        if ($errors !== []) {
            throw new DomainException('validation', 'Please correct the highlighted fields.', 422, $errors);
        }
        $data = ['username' => $username, 'name' => $name, 'role' => $role, 'slam_id' => $slamId, 'is_active' => $active, 'updated_at' => Clock::db()] + $contact;

        return Db::tx(function ($db) use ($id, $data, $password, $actor): int {
            if ($id === null) {
                $db->table('admins')->insert($data + ['password_hash' => self::hash($password), 'password_changed_at' => self::stamp(), 'created_at' => Clock::db()]);
                $id = (int) $db->insertID();
                service('activityLog')->log($actor, 'admin.created', ['target_type' => 'admin', 'target_id' => $id, 'new' => self::safe($data)]);

                return $id;
            }
            $old = Db::row('SELECT * FROM admins WHERE id = ?', [$id]) ?? throw new DomainException('not_found', 'Administrator not found.', 404);
            $db->table('admins')->where('id', $id)->update($data);
            [$o, $n] = \Modules\ActivityLog\Services\ActivityLogService::diff(self::safe($old), self::safe($data));
            $action  = ((int) $old['is_active'] === 1 && $data['is_active'] === 0) ? 'admin.deactivated' : 'admin.updated';
            service('activityLog')->log($actor, $action, ['target_type' => 'admin', 'target_id' => $id, 'old' => $o, 'new' => $n]);

            return $id;
        });
    }

    /** Nobody locks themselves out, and the last active admin always stays. */
    private function assertCanDisable(int $id, Actor $actor, string $field): void
    {
        $msg = $field === 'role' ? 'You cannot change your own role.' : 'You cannot deactivate your own account.';
        if ($actor->isAdmin() && $actor->id === $id) {
            throw new DomainException('validation', $msg, 422, [$field => $msg]);
        }
        $others = (int) Db::value("SELECT COUNT(*) FROM admins WHERE is_active = 1 AND role = 'admin' AND id <> ?", [$id]);
        if ($others === 0) {
            throw new DomainException('validation', 'At least one active administrator must remain.', 422, [$field => 'At least one active administrator must remain.']);
        }
    }

    /**
     * Admin resets someone's password. Leave $password blank to generate one.
     * Returns the new password so the screen can show it once; it is never stored in clear or logged.
     * Every session of that account ends (the session marker no longer matches).
     */
    public function resetPassword(string $kind, int $id, ?string $password, Actor $actor): string
    {
        $table    = $kind === 'admin' ? 'admins' : 'volunteers';
        $password = $password === null || $password === '' ? self::generatePassword() : $password;
        if (mb_strlen($password) < 10) {
            throw new DomainException('validation', 'Password must be at least 10 characters.', 422, ['password' => 'Password must be at least 10 characters.']);
        }
        $row = Db::row("SELECT id, username FROM {$table} WHERE id = ?", [$id]) ?? throw new DomainException('not_found', 'Account not found.', 404);
        db_connect()->table($table)->where('id', $id)->update(['password_hash' => self::hash($password), 'password_changed_at' => self::stamp(), 'updated_at' => Clock::db()]);
        service('activityLog')->log($actor, "{$kind}.password_reset", ['target_type' => $kind, 'target_id' => $id, 'meta' => ['username' => $row['username']]]);

        return $password;
    }

    /** @param array<string, mixed> $input */
    public function updateProfile(int $adminId, array $input, Actor $actor): void
    {
        $old  = Db::row('SELECT * FROM admins WHERE id = ?', [$adminId]) ?? throw new DomainException('not_found', 'Account not found.', 404);
        $name = trim((string) ($input['name'] ?? ''));
        $errors = $this->checkIdentity('admins', $adminId, $old['username'], $name, $input, $contact);
        unset($errors['username']);
        if ($errors !== []) {
            throw new DomainException('validation', 'Please correct the highlighted fields.', 422, $errors);
        }
        $data = ['name' => $name, 'updated_at' => Clock::db()] + $contact;
        db_connect()->table('admins')->where('id', $adminId)->update($data);
        [$o, $n] = \Modules\ActivityLog\Services\ActivityLogService::diff(self::safe($old), self::safe($data));
        service('activityLog')->log($actor, 'admin.updated', ['target_type' => 'admin', 'target_id' => $adminId, 'old' => $o, 'new' => $n, 'meta' => ['self' => true]]);
    }

    /** Own password change; returns the new session marker so this session stays signed in. */
    public function changeOwnPassword(int $adminId, string $current, string $new, string $confirm, Actor $actor): string
    {
        $row = Db::row('SELECT password_hash FROM admins WHERE id = ?', [$adminId]) ?? throw new DomainException('not_found', 'Account not found.', 404);
        if (! password_verify($current, $row['password_hash'])) {
            service('activityLog')->log($actor, 'admin.password_change', ['outcome' => 'failure', 'reason' => 'wrong_current', 'target_type' => 'admin', 'target_id' => $adminId]);
            throw new DomainException('validation', 'Your current password is not correct.', 422, ['current' => 'Your current password is not correct.']);
        }
        if (mb_strlen($new) < 10) {
            throw new DomainException('validation', 'Password must be at least 10 characters.', 422, ['password' => 'Password must be at least 10 characters.']);
        }
        if ($new !== $confirm) {
            throw new DomainException('validation', 'The two new passwords do not match.', 422, ['confirm' => 'The two new passwords do not match.']);
        }
        $stamp = self::stamp();
        db_connect()->table('admins')->where('id', $adminId)->update(['password_hash' => self::hash($new), 'password_changed_at' => $stamp, 'updated_at' => Clock::db()]);
        service('activityLog')->log($actor, 'admin.password_change', ['target_type' => 'admin', 'target_id' => $adminId]);

        return $stamp;
    }

    /** Create an admin (CLI command / admin screen). */
    public function createAdmin(string $username, string $name, string $password, Actor $actor): int
    {
        return $this->saveAdmin(null, ['username' => $username, 'name' => $name, 'password' => $password, 'is_active' => 1], $actor);
    }
}
