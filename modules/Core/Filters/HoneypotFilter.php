<?php

declare(strict_types=1);

namespace Modules\Core\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Libraries\Actor;

/**
 * Bot trap for the public forms (sign-in, check-in, "Am I registered?"). Two traps, both invisible to people:
 *  - a hidden field that real users never fill (bots fill every input), and
 *  - a signed form-load timestamp: a post with no valid stamp, or sent within MIN_SECONDS of the page
 *    being served, did not come from a person reading the form.
 * Use honeypot_fields() inside each protected form. A trapped request gets a neutral "try again" and is logged.
 */
class HoneypotFilter implements FilterInterface
{
    public const FIELD       = 'hp_website';
    public const STAMP       = 'hp_ts';
    public const MIN_SECONDS = 2;

    public static function stamp(?int $now = null): string
    {
        $t = (string) ($now ?? time());

        return $t . '.' . hash_hmac('sha256', $t, self::secret());
    }

    private static function secret(): string
    {
        $key = (string) config('Encryption')->key;

        return $key !== '' ? $key : hash('sha256', ROOTPATH . 'slam-honeypot');
    }

    /** Why the post is not from a person, or null when it looks fine. */
    public static function verdict(string $trap, string $stamp, ?int $now = null): ?string
    {
        if ($trap !== '') {
            return 'trap_filled';
        }
        [$t, $sig] = array_pad(explode('.', $stamp, 2), 2, '');
        if (! ctype_digit($t) || $sig === '' || ! hash_equals(hash_hmac('sha256', $t, self::secret()), $sig)) {
            return 'no_stamp';
        }

        return ($now ?? time()) - (int) $t < self::MIN_SECONDS ? 'too_fast' : null;
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return null;
        }
        $why = self::verdict((string) $request->getPost(self::FIELD), (string) $request->getPost(self::STAMP));
        if ($why === null) {
            return null;
        }
        service('activityLog')->log(Actor::anonymous(), 'bot.blocked', ['outcome' => 'denied', 'reason' => $why, 'meta' => ['path' => $request->getUri()->getPath()]]);

        return redirect()->back()->withInput()->with('error', 'Please try again.');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
