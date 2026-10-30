<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Tests\Support\SlamTestCase;

final class UserManagementTest extends SlamTestCase
{
    private function reason(callable $fn): string
    {
        try {
            $fn();
        } catch (DomainException $e) {
            return $e->reason . ($e->details ? ':' . implode(',', array_keys($e->details)) : '');
        }

        return 'none';
    }

    public function testAdminCreateEditAndValidation(): void
    {
        $auth = service('auth');
        $this->assertSame('validation:username,name,email,phone,password', $this->reason(fn () => $auth->saveAdmin(null, ['username' => 'A', 'name' => 'x', 'email' => 'nope', 'phone' => 'abc', 'password' => 'short'], $this->admin)));

        $id = $auth->saveAdmin(null, ['username' => 'Priya.N', 'name' => 'Priya N', 'email' => 'Priya@Example.org', 'phone' => '98 4700-1234', 'password' => 'a-long-password', 'is_active' => 1], $this->admin);
        $row = Db::row('SELECT * FROM admins WHERE id = ?', [$id]);
        $this->assertSame('priya.n', $row['username']);
        $this->assertSame('priya@example.org', $row['email']);
        $this->assertSame('9847001234', $row['phone']);
        $this->assertTrue(password_verify('a-long-password', $row['password_hash']));
        $this->assertSame('validation:username', $this->reason(fn () => $auth->saveAdmin(null, ['username' => 'priya.n', 'name' => 'Other', 'password' => 'a-long-password'], $this->admin)));

        // Editing without a password keeps the old one.
        $auth->saveAdmin($id, ['username' => 'priya.n', 'name' => 'Priya Nair', 'is_active' => 1], $this->admin);
        $row = Db::row('SELECT * FROM admins WHERE id = ?', [$id]);
        $this->assertSame('Priya Nair', $row['name']);
        $this->assertNull($row['email']);
        $this->assertTrue(password_verify('a-long-password', $row['password_hash']));

        // No hash in the activity log.
        $logged = (string) Db::value("SELECT GROUP_CONCAT(COALESCE(new_values,''), COALESCE(old_values,'')) FROM activity_log WHERE target_type = 'admin'");
        $this->assertStringNotContainsString('$argon', $logged);
        $this->assertStringNotContainsString('$2y$', $logged);
    }

    public function testCannotLockEveryoneOut(): void
    {
        $auth  = service('auth');
        $me    = (int) $this->admin->id;
        $other = $auth->saveAdmin(null, ['username' => 'second', 'name' => 'Second Admin', 'password' => 'a-long-password', 'is_active' => 1], $this->admin);

        $this->assertSame('validation:is_active', $this->reason(fn () => $auth->saveAdmin($me, ['username' => Db::value('SELECT username FROM admins WHERE id = ?', [$me]), 'name' => 'Admin', 'is_active' => 0], $this->admin)));
        // Another admin can be deactivated; then the remaining one is the last.
        $auth->saveAdmin($other, ['username' => 'second', 'name' => 'Second Admin', 'is_active' => 0], $this->admin);
        $this->assertSame(0, (int) Db::value('SELECT is_active FROM admins WHERE id = ?', [$other]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'admin.deactivated'"));
        $stranger = new Actor('admin', 999999, 'x');
        $this->assertSame('validation:is_active', $this->reason(fn () => $auth->saveAdmin($me, ['username' => Db::value('SELECT username FROM admins WHERE id = ?', [$me]), 'name' => 'Admin', 'is_active' => 0], $stranger)));
    }

    public function testResetPasswordGeneratesAndEndsOldSessions(): void
    {
        $auth = service('auth');
        $id   = $auth->saveAdmin(null, ['username' => 'resetme', 'name' => 'Reset Me', 'password' => 'old-password-1', 'is_active' => 1], $this->admin);

        $this->assertSame('validation:password', $this->reason(fn () => $auth->resetPassword('admin', $id, 'short', $this->admin)));
        $this->assertSame('not_found', $this->reason(fn () => $auth->resetPassword('admin', 999999, null, $this->admin)));

        $generated = $auth->resetPassword('admin', $id, '', $this->admin);
        $this->assertSame(12, strlen($generated));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $generated);

        $session = $auth->login('admin', 'resetme', $generated, '10.0.0.1');
        $this->assertSame((string) Db::value('SELECT password_changed_at FROM admins WHERE id = ?', [$id]), $session['pwd']);
        $this->assertSame('bad_credentials', $this->reason(fn () => $auth->login('admin', 'resetme', 'old-password-1', '10.0.0.1')));
        $this->assertNotNull(Db::value('SELECT last_login_at FROM admins WHERE id = ?', [$id]));

        $chosen = $auth->resetPassword('admin', $id, 'chosen-by-admin', $this->admin);
        $this->assertSame('chosen-by-admin', $chosen);
        $logged = (string) Db::value("SELECT GROUP_CONCAT(COALESCE(meta,'')) FROM activity_log WHERE action = 'admin.password_reset'");
        $this->assertStringNotContainsString('chosen-by-admin', $logged);
        $this->assertStringNotContainsString($generated, $logged);
    }

    public function testChangeOwnPasswordNeedsCurrentPassword(): void
    {
        $auth = service('auth');
        $id   = $auth->saveAdmin(null, ['username' => 'selfie', 'name' => 'Self Service', 'password' => 'current-pass-1', 'is_active' => 1], $this->admin);
        $me   = new Actor('admin', $id, 'selfie');

        $this->assertSame('validation:current', $this->reason(fn () => $auth->changeOwnPassword($id, 'wrong', 'new-password-1', 'new-password-1', $me)));
        $this->assertSame('validation:password', $this->reason(fn () => $auth->changeOwnPassword($id, 'current-pass-1', 'short', 'short', $me)));
        $this->assertSame('validation:confirm', $this->reason(fn () => $auth->changeOwnPassword($id, 'current-pass-1', 'new-password-1', 'new-password-2', $me)));

        $stamp = $auth->changeOwnPassword($id, 'current-pass-1', 'new-password-1', 'new-password-1', $me);
        $this->assertSame($stamp, Db::value('SELECT password_changed_at FROM admins WHERE id = ?', [$id]));
        $this->assertSame('selfie', $auth->login('admin', 'selfie', 'new-password-1', '10.0.0.2')['label']);

        $auth->updateProfile($id, ['name' => 'Self S', 'email' => 'self@example.org', 'phone' => '+91 98470 01234'], $me);
        $row = Db::row('SELECT name, email, phone, username FROM admins WHERE id = ?', [$id]);
        $this->assertSame(['Self S', 'self@example.org', '+919847001234', 'selfie'], array_values($row));
        $this->assertSame('validation:email', $this->reason(fn () => $auth->updateProfile($id, ['name' => 'Self S', 'email' => 'bad'], $me)));
    }

    public function testVolunteerContactAndReset(): void
    {
        $s    = $this->makeSlam(1, 1, 1);
        $auth = service('auth');
        $id   = $auth->saveVolunteer(null, ['username' => 'desk1', 'name' => 'Desk One', 'slam_id' => $s['id'], 'email' => 'desk@example.org', 'phone' => '9847001234', 'password' => 'volunteer-pass', 'is_active' => 1], $this->admin);
        $this->assertSame('desk@example.org', Db::value('SELECT email FROM volunteers WHERE id = ?', [$id]));
        $new = $auth->resetPassword('volunteer', $id, null, $this->admin);
        $this->assertSame('desk1', $auth->login('volunteer', 'desk1', $new, '10.0.0.3')['label']);
        $this->assertSame('bad_credentials', $this->reason(fn () => $auth->login('volunteer', 'desk1', 'volunteer-pass', '10.0.0.3')));
        $this->assertStringNotContainsString('$argon', (string) Db::value("SELECT GROUP_CONCAT(COALESCE(new_values,'')) FROM activity_log WHERE target_type = 'volunteer'"));
    }
}
