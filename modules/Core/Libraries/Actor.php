<?php

declare(strict_types=1);

namespace Modules\Core\Libraries;

/**
 * Who is performing the current action. Built once per request from the session
 * (see Actor::current()) and passed into services, which never read the session.
 */
final class Actor
{
    public function __construct(
        public readonly string $type,      // admin | volunteer | user | judge | system | anonymous
        public readonly ?int $id = null,
        public readonly ?string $label = null,
        public readonly ?int $slamId = null,
    ) {
    }

    public static function system(): self
    {
        return new self('system', null, 'system');
    }

    public static function anonymous(): self
    {
        return new self('anonymous');
    }

    public function isAdmin(): bool
    {
        return $this->type === 'admin';
    }

    public function isVolunteer(): bool
    {
        return $this->type === 'volunteer';
    }

    public function isReviewer(): bool
    {
        return $this->type === 'user' || $this->type === 'judge';
    }

    /** Phase-log changed_by_type: only admin or system. */
    public function phaseActorType(): string
    {
        return $this->isAdmin() ? 'admin' : 'system';
    }

    public static function current(): self
    {
        $session = session();
        $auth    = $session->get('auth');
        if (is_array($auth)) {
            return new self($auth['type'], (int) $auth['id'], $auth['label'], isset($auth['slam_id']) ? (int) $auth['slam_id'] : null);
        }
        $reviewer = $session->get('reviewer');
        if (is_array($reviewer)) {
            return new self($reviewer['user_type'], (int) $reviewer['attendee_id'], $reviewer['badge_id'], (int) $reviewer['slam_id']);
        }

        return self::anonymous();
    }
}
