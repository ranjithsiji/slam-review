<?php

declare(strict_types=1);

namespace Modules\Schedule\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Modules\Core\Libraries\PdfFactory;
use Modules\Schedule\Services\ScheduleService;

/** Schedule maker (Vue screen) and its API, plus run-sheet exports. */
class ScheduleController extends BaseController
{
    public function page(int $id): string
    {
        $slam = service('slams')->find($id);

        return $this->render('Modules\Schedule\Views\maker', [
            'title' => 'Schedule — ' . $slam['name'], 'slam' => $slam,
            'otherSlams' => array_values(array_filter(service('slams')->all(), static fn ($s) => (int) $s['id'] !== $id)),
        ]);
    }

    /** Everything the schedule maker needs. */
    public function get(int $id): ResponseInterface
    {
        return $this->api(function () use ($id) {
            $slam  = service('slams')->find($id);
            $rows  = service('schedule')->items($id);
            $perfs = [];
            foreach (Db::rows(
                'SELECT p.id, p.topic, p.is_active, pr.id AS presenter_id, pr.name, pr.photo_thumb_path FROM performances p
                 JOIN presenters pr ON pr.id = p.presenter_id WHERE p.slam_id = ? ORDER BY pr.name',
                [$id],
            ) as $p) {
                $perfs[] = [
                    'id' => (int) $p['id'], 'presenter_id' => (int) $p['presenter_id'], 'presenter_name' => $p['name'], 'topic' => $p['topic'],
                    'is_active' => (bool) $p['is_active'], 'thumb' => $p['photo_thumb_path'] ? '/' . $p['photo_thumb_path'] : null,
                ];
            }
            $extra = [];
            foreach ($rows as $r) {
                $extra[(int) $r['id']] = [
                    'review_count' => (int) $r['review_count'], 'actual_open_at' => Clock::iso($r['window_opened_at'] ?? $r['actual_start_at']),
                    'actual_close_at' => Clock::iso($r['window_closed_at'] ?? ($r['status'] === 'closed' ? $r['ends_at'] : null)),
                ];
            }
            $live = ScheduleService::isLive($slam);

            return [
                'server_now' => Clock::iso(Clock::db()),
                'slam' => [
                    'id' => (int) $slam['id'], 'name' => $slam['name'], 'status' => $slam['status'], 'schedule_version' => (int) $slam['schedule_version'],
                    'opens_at' => Clock::iso($slam['opens_at']), 'checkin_closes_at' => Clock::iso($slam['checkin_closes_at']),
                    'reviews_start_at' => Clock::iso($slam['reviews_start_at']), 'closes_at' => Clock::iso($slam['closes_at']),
                    'default_review_minutes' => (int) $slam['default_review_minutes'], 'changeover_minutes' => (int) $slam['changeover_minutes'],
                    'edit_window_minutes' => (int) $slam['edit_window_minutes'], 'default_tea_break_minutes' => (int) $slam['default_tea_break_minutes'],
                    'default_lunch_break_minutes' => (int) $slam['default_lunch_break_minutes'],
                ],
                'live' => $live,
                'locked' => in_array($slam['status'], ['reviews_complete', 'revision', 'revision_closed', 'closed', 'marks_calculated', 'ended'], true),
                'items' => array_map(static fn ($d) => $d + ($extra[$d['id']] ?? []), service('schedule')->toDraft($rows)),
                'performances' => $perfs,
                'projection' => $live ? $this->projection($slam) : null,
            ];
        });
    }

    private function projection(array $slam): array
    {
        $p = service('slamPhase')->projection($slam);

        return [
            'late_minutes' => $p['late_minutes'], 'projected_end' => Clock::iso($p['projected_end']), 'past_hard_stop' => $p['past_hard_stop'],
            'items' => array_map(static fn ($i) => ['id' => (int) $i['id'], 'projected_start_at' => Clock::iso($i['projected_start_at'])], $p['items']),
        ];
    }

    public function save(int $id): ResponseInterface
    {
        $in = $this->input();

        return $this->api(fn () => service('schedule')->saveDraft(
            $id,
            (array) ($in['items'] ?? []),
            (int) ($in['base_version'] ?? 0),
            $this->actor(),
            isset($in['note']) ? mb_substr((string) $in['note'], 0, 255) : null,
            isset($in['generated_seed']) ? ['generated_seed' => (int) $in['generated_seed']] : [],
        ));
    }

    public function validateDraft(int $id): ResponseInterface
    {
        $in = $this->input();

        return $this->api(fn () => service('schedule')->validate(service('slams')->find($id), service('schedule')->normalise((array) ($in['items'] ?? []))));
    }

    /** Re-flow a client draft (used for reorder previews inside the maker). */
    public function reflowDraft(int $id): ResponseInterface
    {
        $in = $this->input();

        return $this->api(function () use ($id, $in) {
            $svc  = service('schedule');
            $slam = service('slams')->find($id);
            $r    = $svc->reflow($slam, $svc->normalise((array) ($in['items'] ?? [])), (int) ($in['from'] ?? 0), ! empty($in['move_pinned']));
            $v    = $svc->validate($slam, $r['items']);

            return ['items' => $svc->draftOut($r['items']), 'errors' => [...$r['errors'], ...$v['errors']], 'warnings' => $v['warnings']];
        });
    }

    public function addItem(int $id): ResponseInterface
    {
        $in = $this->input();

        return $this->api(fn () => ['id' => service('schedule')->appendItem($id, $in, $this->actor())]);
    }

    public function generate(int $id): ResponseInterface
    {
        return $this->api(fn () => service('schedule')->generate($id, $this->input(), $this->actor()));
    }

    public function reorder(int $id): ResponseInterface
    {
        $in = $this->input();

        return $this->api(fn () => service('schedule')->reorder(
            $id,
            array_map('intval', (array) ($in['order'] ?? [])),
            (bool) ($in['reflow'] ?? true),
            (bool) ($in['keep_times'] ?? false),
            (bool) ($in['preview'] ?? true),
            $this->actor(),
        ));
    }

    public function reflowRemaining(int $id): ResponseInterface
    {
        $in = $this->input();

        return $this->api(fn () => service('schedule')->reflowRemaining($id, ! empty($in['include_pinned']), (bool) ($in['preview'] ?? true), $this->actor()));
    }

    public function snapshots(int $id): ResponseInterface
    {
        return $this->api(fn () => ['data' => array_map(static fn ($s) => [
            'version' => (int) $s['version'], 'saved_at' => Clock::iso($s['saved_at']), 'note' => $s['note'], 'by' => $s['saved_by_name'] ?? 'system',
        ], service('schedule')->snapshots($id))]);
    }

    public function restore(int $id, int $version): ResponseInterface
    {
        return $this->api(fn () => ['items' => service('schedule')->restoreDraft($id, $version, $this->actor())]);
    }

    public function copyStructure(int $id, int $from): ResponseInterface
    {
        return $this->api(fn () => service('schedule')->copyStructure($id, $from, $this->actor()));
    }

    public function updateItem(int $itemId): ResponseInterface
    {
        $in = $this->input();

        return $this->api(function () use ($itemId, $in) {
            service('schedule')->updateItem($itemId, array_intersect_key($in, array_flip(['planned_start_at', 'duration_minutes', 'is_pinned', 'label'])), $this->actor());

            return ['ok' => true];
        });
    }

    public function deleteItem(int $itemId): ResponseInterface
    {
        return $this->api(function () use ($itemId) {
            service('schedule')->deleteItem($itemId, $this->actor());

            return ['ok' => true];
        });
    }

    private function itemSlam(int $itemId): int
    {
        return (int) (Db::value('SELECT slam_id FROM schedule_items WHERE id = ?', [$itemId]) ?? throw new DomainException('not_found', 'Slot not found.', 404));
    }

    public function extendBreak(int $itemId): ResponseInterface
    {
        $in = $this->input();

        return $this->api(function () use ($itemId, $in) {
            service('slamPhase')->extendBreak($this->itemSlam($itemId), $itemId, (int) ($in['minutes'] ?? 0), $this->actor());

            return ['ok' => true];
        });
    }

    public function endBreak(int $itemId): ResponseInterface
    {
        return $this->api(function () use ($itemId) {
            service('slamPhase')->endBreak($this->itemSlam($itemId), $itemId, $this->actor());

            return ['ok' => true];
        });
    }

    /** @return list<array<string, mixed>> */
    private function runSheetRows(int $id): array
    {
        return array_values(array_filter(service('schedule')->items($id), static fn ($r) => $r['status'] !== 'skipped'));
    }

    public function runSheet(): string
    {
        $auth   = session('auth');
        $slamId = $auth['type'] === 'volunteer' ? (int) $auth['slam_id'] : (int) $this->request->getGet('slam');
        $slam   = service('slams')->find($slamId);

        return $this->render('Modules\Schedule\Views\run_sheet', ['title' => 'Run-sheet', 'slam' => $slam, 'rows' => $this->runSheetRows($slamId)], $auth['type'] === 'volunteer' ? 'volunteer' : 'admin');
    }

    public function exportPdf(int $id): ResponseInterface
    {
        $slam = service('slams')->find($id);
        $rows = $this->runSheetRows($id);
        $html = view('Modules\Schedule\Views\run_sheet_pdf', ['slam' => $slam, 'rows' => $rows]);
        $pdf  = PdfFactory::make(['margin_top' => 12, 'margin_bottom' => 12]);
        $pdf->SetTitle('Run-sheet — ' . $slam['name']);
        $pdf->WriteHTML($html);
        service('activityLog')->log($this->actor(), 'schedule.exported', ['slam_id' => $id, 'meta' => ['format' => 'pdf']]);

        return $this->download($pdf->Output('', 'S'), 'run-sheet-' . $slam['slug'] . '.pdf', 'application/pdf');
    }

    public function exportCsv(int $id): ResponseInterface
    {
        $slam = service('slams')->find($id);
        $fh   = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['#', 'start (' . Clock::tzLabel() . ')', 'end (' . Clock::tzLabel() . ')', 'minutes', 'type', 'presenter / label', 'topic', 'pinned', 'status'], ',', '"', '');
        foreach ($this->runSheetRows($id) as $i => $r) {
            fputcsv($fh, array_map([\Modules\Attendees\Services\AttendeeService::class, 'csvSafe'], [
                (string) ($i + 1), Clock::local($r['planned_start_at'], 'H:i'), Clock::local(Clock::addMinutes($r['planned_start_at'], (int) $r['duration_minutes']), 'H:i'),
                (string) $r['duration_minutes'], $r['item_type'] === 'break' ? $r['break_type'] . ' break' : 'performance',
                $r['item_type'] === 'break' ? $r['label'] : $r['presenter_name'], (string) $r['topic'], $r['is_pinned'] ? 'yes' : '', $r['status'],
            ]), ',', '"', '');
        }
        rewind($fh);
        service('activityLog')->log($this->actor(), 'schedule.exported', ['slam_id' => $id, 'meta' => ['format' => 'csv']]);

        return $this->download((string) stream_get_contents($fh), 'run-sheet-' . $slam['slug'] . '.csv', 'text/csv; charset=utf-8');
    }
}
