<?php

declare(strict_types=1);

namespace Modules\PublicSite\Services;

use Modules\Core\Libraries\Db;

/**
 * The presenters map: every presenter of a published slam, placed at their institution. Places and coordinates are
 * kept by hand in modules/PublicSite/Data/presenter-places.json; a presenter belongs to the first place whose match
 * text appears in their institution. Anyone not matched is listed under the map instead. Only public fields are used
 * (the same as the public schedule): name, institution, topic, thumbnail and the slam.
 */
class PresenterMapService
{
    public const CACHE_SECONDS = 300;

    /** @return list<array{name: string, match: list<string>, lat: float, lng: float, approx?: bool}> */
    public static function places(): array
    {
        $doc = json_decode((string) file_get_contents(dirname(__DIR__) . '/Data/presenter-places.json'), true);

        return $doc['places'] ?? [];
    }

    /** The first place whose match text is in the institution, or null. */
    public static function placeFor(?string $institution, array $places): ?int
    {
        $text = mb_strtolower(trim((string) $institution));
        if ($text === '') {
            return null;
        }
        foreach ($places as $i => $p) {
            foreach ($p['match'] as $m) {
                if (str_contains($text, mb_strtolower($m))) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @return array{slams: list<array<string, mixed>>, places: list<array<string, mixed>>, unplaced: list<array<string, mixed>>, total: int}
     */
    public function data(): array
    {
        $cache = cache();
        $key   = 'presenter_map_v1';
        if (is_array($hit = $cache->get($key))) {
            return $hit;
        }
        helper('Modules\Core\Helpers\ui');
        $rows = Db::rows(
            "SELECT s.id AS slam_id, s.name AS slam, s.slug, s.type, s.opens_at, pr.name, pr.designation_or_institution AS institution, p.topic, pr.photo_thumb_path
               FROM performances p JOIN presenters pr ON pr.id = p.presenter_id JOIN slams s ON s.id = p.slam_id
              WHERE s.schedule_is_public = 1 AND s.status <> 'draft' AND p.is_active = 1
                AND EXISTS (SELECT 1 FROM schedule_items si WHERE si.performance_id = p.id AND si.status <> 'skipped')
              ORDER BY s.opens_at, pr.name",
        );
        $places = self::places();
        $out    = [];
        $slams  = [];
        $unplaced = [];
        foreach ($rows as $r) {
            $slams[(int) $r['slam_id']] ??= ['slug' => $r['slug'], 'name' => $r['slam'], 'type' => $r['type'], 'date' => local_time($r['opens_at'], 'j M Y')];
            $person = [
                'name' => $r['name'], 'institution' => $r['institution'], 'topic' => $r['topic'], 'slam' => $r['slug'],
                'thumb' => $r['photo_thumb_path'] !== null ? '/' . $r['photo_thumb_path'] : null,
            ];
            $i = self::placeFor($r['institution'], $places);
            if ($i === null) {
                $unplaced[] = $person;
                continue;
            }
            $out[$i] ??= ['name' => $places[$i]['name'], 'lat' => $places[$i]['lat'], 'lng' => $places[$i]['lng'], 'approx' => ! empty($places[$i]['approx']), 'people' => []];
            $out[$i]['people'][] = $person;
        }
        usort($out, static fn ($a, $b) => count($b['people']) <=> count($a['people']) ?: strcmp($a['name'], $b['name']));
        $data = ['slams' => array_values($slams), 'places' => array_values($out), 'unplaced' => $unplaced, 'total' => count($rows)];
        $cache->save($key, $data, self::CACHE_SECONDS);

        return $data;
    }
}
