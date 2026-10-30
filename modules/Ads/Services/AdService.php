<?php

declare(strict_types=1);

namespace Modules\Ads\Services;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/** Break ads: plain text or an image, with an optional link. Admin-managed per slam; shown only while a break runs. */
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
        return array_map(static fn ($a) => [
            'id' => (int) $a['id'], 'kind' => $a['kind'], 'sponsor' => $a['sponsor'], 'headline' => $a['headline'], 'body' => $a['body'],
            'image' => $a['image_path'] !== null ? '/' . $a['image_path'] : null, 'link' => $a['link_url'],
        ], Db::rows('SELECT * FROM ads WHERE slam_id = ? AND is_active = 1 ORDER BY sort_order, id', [$slamId]));
    }

    public function all(int $slamId): array
    {
        return Db::rows('SELECT * FROM ads WHERE slam_id = ? ORDER BY sort_order, id', [$slamId]);
    }

    /**
     * @param array<string, mixed> $in
     * @param ?string $imageTmp path of an uploaded image (required for a new image ad)
     */
    public function save(int $slamId, ?int $id, array $in, ?string $imageTmp, Actor $actor): int
    {
        $old  = $id !== null ? (Db::row('SELECT * FROM ads WHERE id = ? AND slam_id = ?', [$id, $slamId]) ?? throw new DomainException('not_found', 'Ad not found.', 404)) : null;
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
            service('activityLog')->log($actor, 'ad.created', ['slam_id' => $slamId, 'target_type' => 'ad', 'target_id' => $id, 'new' => $data]);
        } else {
            $db->table('ads')->where('id', $id)->update($data);
            service('activityLog')->log($actor, 'ad.updated', ['slam_id' => $slamId, 'target_type' => 'ad', 'target_id' => $id, 'new' => $data]);
        }
        service('publicCache')->purge($slamId);

        return $id;
    }

    public function delete(int $slamId, int $id, Actor $actor): void
    {
        $ad = Db::row('SELECT * FROM ads WHERE id = ? AND slam_id = ?', [$id, $slamId]) ?? throw new DomainException('not_found', 'Ad not found.', 404);
        db_connect()->table('ads')->where('id', $id)->delete();
        service('photos')->delete($ad['image_path'], $ad['image_thumb_path']);
        service('activityLog')->log($actor, 'ad.deleted', ['slam_id' => $slamId, 'target_type' => 'ad', 'target_id' => $id]);
        service('publicCache')->purge($slamId);
    }
}
