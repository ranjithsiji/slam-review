<?php

declare(strict_types=1);

namespace Modules\Core\Libraries;

/**
 * Short-lived caches for data every phone polls (public schedule JSON, the slam-level
 * part of the reviewer live state). Purged on every schedule or phase change, so the
 * cache only smooths load and never serves a stale phase for more than one write.
 */
class PublicCache
{
    public function key(string $kind, int $slamId): string
    {
        return 'slam_' . $kind . '_' . $slamId;
    }

    public function remember(string $kind, int $slamId, int $ttl, callable $build): mixed
    {
        $cache = cache();
        $key   = $this->key($kind, $slamId);
        $hit   = $cache->get($key);
        if ($hit !== null) {
            return $hit;
        }
        $value = $build();
        $cache->save($key, $value, $ttl);

        return $value;
    }

    public function purge(int $slamId): void
    {
        foreach (['public', 'live', 'tickcheck'] as $kind) {
            cache()->delete($this->key($kind, $slamId));
        }
    }
}
