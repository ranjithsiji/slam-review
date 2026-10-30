<?php

declare(strict_types=1);

namespace Modules\Core\Libraries;

use RuntimeException;

/**
 * A rule refused an action (forbidden phase move, window closed, ...).
 * $reason is a short machine code that also goes into the activity log;
 * the message is safe to show to the user. $status is the HTTP status to use.
 */
class DomainException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
