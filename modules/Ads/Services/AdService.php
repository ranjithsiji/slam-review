<?php

declare(strict_types=1);

namespace Modules\Ads\Services;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/**
 * Break ads: plain text or an image, with an optional link, shown only while a break runs. An ad either belongs to one
 * slam (slam_id set: that slam's own ad) or to the ad pool (slam_id NULL), from which each slam chooses (slam_ads).
 * A slam's breaks show its own active ads, then the active pool ads it chose.
 */
class AdService
{
    /** What phones and the public page receive (active ads in order; only allow-listed fields). */
    public function forBreak(int $slamId): array
    {
        try {
            return $this->activeAds($slamId);
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            log_message('error', 'ads unavailable (run the migrations and the grants): ' . $e->getMessage());

            return []; // a break must never break the live screens
        }
    }

    private function activeAds(int $slamId): array
    {
        $rows = [
            ...Db::rows('SELECT * FROM ads WHERE slam_id = ? AND is_active = 1 ORDER BY sort_order, id', [$slamId]),
            ...Db::rows('SELECT a.* FROM slam_ads sa JOIN ads a ON a.id = sa.ad_id WHERE sa.slam_id = ? AND a.slam_id IS NULL AND a.is_active = 1 ORDER BY sa.sort_order, a.sort_order, a.id', [$slamId]),
        ];

        return array_map(static fn ($a) => [
            'id' => (int) $a['id'], 'kind' => $a['kind'], 'sponsor' => $a['sponsor'], 'headline' => $a['headline'], 'body' => $a['body'],
            'image' => $a['image_path'] !== null ? '/' . $a['image_path'] : null, 'link' => $a['link_url'],
        ], $rows);
    }

    /** A slam's own ads. */
    public function all(int $slamId): array
    {
        return Db::rows('SELECT * FROM ads WHERE slam_id = ? ORDER BY sort_order, id', [$slamId]);
    }

    /** The ad pool, each ad with the names of the slams that show it. */
    public function pool(): array
    {
        $ads  = Db::rows('SELECT * FROM ads WHERE slam_id IS NULL ORDER BY sort_order, id');
        $used = [];
        foreach (Db::rows('SELECT sa.ad_id, s.id, s.name FROM slam_ads sa JOIN slams s ON s.id = sa.slam_id ORDER BY s.opens_at, s.id') as $r) {
            $used[(int) $r['ad_id']][] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }
        foreach ($ads as &$a) {
            $a['slams'] = $used[(int) $a['id']] ?? [];
        }
        unset($a);

        return $ads;
    }

    /** Pool ads this slam shows. @return list<int> */
    public function chosenIds(int $slamId): array
    {
        return array_map('intval', array_column(Db::rows('SELECT ad_id FROM slam_ads WHERE slam_id = ? ORDER BY sort_order, ad_id', [$slamId]), 'ad_id'));
    }

    /** One ad of a slam, or (slam null) of the pool. */
    public function find(?int $slamId, int $id): ?array
    {
        return $slamId === null
            ? Db::row('SELECT * FROM ads WHERE id = ? AND slam_id IS NULL', [$id])
            : Db::row('SELECT * FROM ads WHERE id = ? AND slam_id = ?', [$id, $slamId]);
    }

    /** The slams whose live screens show an ad (to clear their caches when it changes). @return list<int> */
    private function slamsShowing(?int $slamId, int $adId): array
    {
        return $slamId !== null ? [$slamId] : array_map('intval', array_column(Db::rows('SELECT slam_id FROM slam_ads WHERE ad_id = ?', [$adId]), 'slam_id'));
    }

    private function purge(array $slamIds): void
    {
        foreach (array_unique($slamIds) as $id) {
            service('publicCache')->purge((int) $id);
        }
    }

    /**
     * Choose which pool ads a slam shows, in the order given. Only pool ads can be chosen.
     *
     * @param list<int|string> $adIds
     */
    public function choosePool(int $slamId, array $adIds, Actor $actor): void
    {
        $ids  = array_values(array_unique(array_map('intval', $adIds)));
        $pool = array_map('intval', array_column(Db::rows('SELECT id FROM ads WHERE slam_id IS NULL'), 'id'));
        if (array_diff($ids, $pool) !== []) {
            throw new DomainException('validation', 'Only ads from the ad pool can be chosen.', 422);
        }
        $old = $this->chosenIds($slamId);
        Db::tx(function ($db) use ($slamId, $ids, $actor, $old): void {
            Db::lockSlam($slamId);
            $db->table('slam_ads')->where('slam_id', $slamId)->delete();
            foreach ($ids as $i => $id) {
                $db->table('slam_ads')->insert(['slam_id' => $slamId, 'ad_id' => $id, 'sort_order' => $i + 1, 'added_by' => $actor->isAdmin() ? $actor->id : null, 'created_at' => Clock::db()]);
            }
            service('activityLog')->log($actor, 'ad.pool_chosen', ['slam_id' => $slamId, 'target_type' => 'slam', 'target_id' => $slamId, 'old' => ['pool_ads' => $old], 'new' => ['pool_ads' => $ids]]);
        });
        $this->purge([$slamId]);
    }

    /**
     * @param array<string, mixed> $in
     * @param ?string $imageTmp path of an uploaded image (required for a new image ad)
     */
    /** Save a slam's own ad, or (slam null) a pool ad. */
    public function save(?int $slamId, ?int $id, array $in, ?string $imageTmp, Actor $actor): int
    {
        $old  = $id !== null ? ($this->find($slamId, $id) ?? throw new DomainException('not_found', 'Ad not found.', 404)) : null;
        $kind = ($in['kind'] ?? $old['kind'] ?? 'text') === 'image' ? 'image' : 'text';
        $errors = [];
        $sponsor  = trim((string) ($in['sponsor'] ?? ''));
        $headline = trim((string) ($in['headline'] ?? ''));
        $body     = trim((string) ($in['body'] ?? ''));
        $link     = trim((string) ($in['link_url'] ?? ''));
        if (mb_strlen($sponsor) > 120) {
            $errors['sponsor'] = 'Sponsor name: up to 120 characters.';
        }
        if (mb_strlen($headline) > 160) {
            $errors['headline'] = 'Headline: up to 160 characters.';
        }
        if (mb_strlen($body) > 600) {
            $errors['body'] = 'Text: up to 600 characters.';
        }
        if ($kind === 'text' && $headline === '' && $body === '') {
            $errors['headline'] = 'A text ad needs a headline or some text.';
        }
        if ($link !== '' && (! preg_match('#^https?://#i', $link) || ! filter_var($link, FILTER_VALIDATE_URL) || mb_strlen($link) > 500)) {
            $errors['link_url'] = 'Link must be a full http:// or https:// address.';
        }
        if ($kind === 'image' && $imageTmp === null && ($old === null || $old['image_path'] === null)) {
            $errors['image'] = 'Choose an image for an image ad.';
        }
        if ($errors !== []) {
            throw new DomainException('validation', 'Please correct the highlighted fields.', 422, $errors);
        }
        $data = [
            'kind' => $kind, 'sponsor' => $sponsor !== '' ? $sponsor : null, 'headline' => $headline !== '' ? $headline : null, 'body' => $body !== '' ? $body : null,
            'link_url' => $link !== '' ? $link : null, 'is_active' => ! empty($in['is_active']) ? 1 : 0, 'sort_order' => (int) ($in['sort_order'] ?? $old['sort_order'] ?? 0),
            'updated_at' => Clock::db(),
        ];
        if ($imageTmp !== null) {
            $paths = service('photos')->store($imageTmp, 1000, 300, 'uploads/ads');
            $data['image_path'] = $paths['photo_path'];
            $data['image_thumb_path'] = $paths['photo_thumb_path'];
            if ($old !== null) {
                service('photos')->delete($old['image_path'], $old['image_thumb_path']);
            }
        }
        $db = db_connect();
        if ($old === null) {
            $db->table('ads')->insert($data + ['slam_id' => $slamId, 'created_at' => Clock::db()]);
            $id = (int) $db->insertID();
            service('activityLog')->log($actor, 'ad.created', ['slam_id' => $slamId, 'target_type' => 'ad', 'target_id' => $id, 'new' => $data, 'meta' => ['pool' => $slamId === null]]);
        } else {
            $db->table('ads')->where('id', $id)->update($data);
            service('activityLog')->log($actor, 'ad.updated', ['slam_id' => $slamId, 'target_type' => 'ad', 'target_id' => $id, 'new' => $data, 'meta' => ['pool' => $slamId === null]]);
        }
        $this->purge($this->slamsShowing($slamId, $id));

        return $id;
    }

    /** Delete a slam's own ad, or (slam null) a pool ad, which also leaves every slam that showed it. */
    public function delete(?int $slamId, int $id, Actor $actor): void
    {
        $ad      = $this->find($slamId, $id) ?? throw new DomainException('not_found', 'Ad not found.', 404);
        $showing = $this->slamsShowing($slamId, $id);
        Db::tx(function ($db) use ($id, $slamId, $actor, $showing): void {
            $db->table('slam_ads')->where('ad_id', $id)->delete();
            $db->table('ads')->where('id', $id)->delete();
            service('activityLog')->log($actor, 'ad.deleted', ['slam_id' => $slamId, 'target_type' => 'ad', 'target_id' => $id, 'meta' => ['pool' => $slamId === null, 'was_shown_at' => $showing]]);
        });
        service('photos')->delete($ad['image_path'], $ad['image_thumb_path']);
        $this->purge($showing);
    }
}
